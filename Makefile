.DEFAULT_GOAL := help
COMPOSE := docker compose

.PHONY: help build up down restart logs ps icons win-codesign-cache migrate fresh shell tinker composer artisan npm octane-build octane-up octane-down octane-logs app-bundle app-desktop app-desktop-win app-mobile app-apk

# Where the packaged apps look for the API and the WebSocket server.
#
# These are baked into the bundle at generate time — a packaged app has no environment to
# read at startup — so they must be addresses the *device* can reach. `localhost` on a phone
# means the phone. Anything left unset here falls through to the frontend container's own
# compose environment, which is what you want for a desktop build against a local stack and
# never what you want for a release.
#
#   make app-mobile API_BASE=http://192.168.1.20:8000 REVERB_HOST=192.168.1.20
#   make app-desktop-win API_BASE=https://api.example.com \
#                        REVERB_HOST=ws.example.com REVERB_PORT=443 REVERB_SCHEME=https \
#                        REVERB_KEY=abc123
API_BASE ?=
REVERB_HOST ?=
REVERB_KEY ?=
REVERB_PORT ?=
REVERB_SCHEME ?=

# Only the ones actually given are passed through, so an unset variable keeps the container's
# value rather than being overwritten with an empty string.
BUNDLE_ENV = \
	$(if $(API_BASE),-e NUXT_PUBLIC_API_BASE=$(API_BASE)) \
	$(if $(REVERB_HOST),-e NUXT_PUBLIC_REVERB_HOST=$(REVERB_HOST)) \
	$(if $(REVERB_KEY),-e NUXT_PUBLIC_REVERB_KEY=$(REVERB_KEY)) \
	$(if $(REVERB_PORT),-e NUXT_PUBLIC_REVERB_PORT=$(REVERB_PORT)) \
	$(if $(REVERB_SCHEME),-e NUXT_PUBLIC_REVERB_SCHEME=$(REVERB_SCHEME))

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build all images (app first, so reverb/worker can build FROM it)
	$(COMPOSE) build app
	$(COMPOSE) build

up: build ## Build, start the whole stack, and run migrations
	$(COMPOSE) up -d
	@echo "Waiting for the database to be ready..."
	@until $(COMPOSE) exec -T postgres pg_isready -U $${POSTGRES_USER:-sidechat} >/dev/null 2>&1; do sleep 1; done
	$(COMPOSE) exec -T app php artisan migrate --force
	@echo ""
	@echo "  API      -> http://localhost:$${APP_PORT:-8000}/api/ping"
	@echo "  Frontend -> http://localhost:$${FRONTEND_PORT:-3000}  (first boot installs npm deps, give it a minute)"
	@echo "  Reverb   -> ws://localhost:$${REVERB_PORT:-8080}"

down: ## Stop and remove containers (keeps volumes/data)
	$(COMPOSE) down

restart: ## Restart all services
	$(COMPOSE) restart

logs: ## Tail logs for all services
	$(COMPOSE) logs -f --tail=100

ps: ## Show service status
	$(COMPOSE) ps

migrate: ## Run database migrations
	$(COMPOSE) exec -T app php artisan migrate --force

fresh: ## Drop everything and re-migrate (DESTROYS data)
	$(COMPOSE) exec -T app php artisan migrate:fresh --force

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

tinker: ## Open Laravel Tinker
	$(COMPOSE) exec app php artisan tinker

composer: ## Run composer, e.g. `make composer c="require foo/bar"`
	$(COMPOSE) run --rm --entrypoint composer app $(c)

artisan: ## Run artisan, e.g. `make artisan c="make:model Message -m"`
	$(COMPOSE) exec -T app php artisan $(c)

npm: ## Run npm in the frontend, e.g. `make npm c="run build"`
	$(COMPOSE) exec frontend npm $(c)

app-bundle: ## Generate the static SPA the native apps package (API_BASE=... REVERB_*=...)
	@echo "Bundling with: $(if $(strip $(BUNDLE_ENV)),$(strip $(BUNDLE_ENV)),container defaults)"
	$(COMPOSE) exec -T $(BUNDLE_ENV) frontend npm run generate

# `cd`, not `npm --prefix`: the prefix flag tells npm which package.json to read but leaves
# the working directory where it was, and electron-builder resolves the project from the cwd —
# so it goes looking for a package.json at the repo root, which doesn't have one.
app-desktop: app-bundle ## Package the Electron desktop app into desktop/dist (needs Linux node)
	@case "$$(command -v npm)" in \
		/mnt/*) echo "npm here is Windows npm, and it cannot build inside the WSL filesystem:"; \
			echo "cmd.exe refuses a UNC working directory, so package install scripts run from C:\\\\Windows."; \
			echo "Use \`make app-desktop-win\`, which stages the build onto the Windows disk."; \
			exit 1 ;; \
	esac
	cd desktop && npm install && npm run dist

# Where a Windows desktop build is assembled. Node on this machine is Windows node, and
# Windows npm cannot install into the WSL filesystem: it fails to clean up files WSL created
# (EPERM/ENOTEMPTY) and unpacks Linux binaries a Windows build can't use. So the shell is
# staged onto the Windows disk and built there instead.
WIN_USER ?= $(shell cmd.exe /c 'echo %USERNAME%' 2>/dev/null | tr -d '\r')
WIN_STAGE ?= /mnt/c/Users/$(WIN_USER)/side-chat-desktop

app-desktop-win: app-bundle ## Build the Windows desktop app via a staging dir on C:
	# Clear the staged *inputs* only. Wiping the whole directory would take node_modules with
	# it (a full npm install every build) and would trip over dist/: if the previously built
	# app is still running, Windows holds its exe and DLLs open and rm fails with EIO/EPERM
	# before the build even starts. electron-builder overwrites dist/ itself, and gives a
	# comprehensible error if the running app really is in the way.
	rm -rf "$(WIN_STAGE)/main.js" "$(WIN_STAGE)/preload.js" "$(WIN_STAGE)/remote-control.js" \
		"$(WIN_STAGE)/package.json" "$(WIN_STAGE)/build" "$(WIN_STAGE)/web"
	mkdir -p "$(WIN_STAGE)"
	# Keep in step with the `files` allowlist in desktop/package.json — anything main.js
	# requires has to be staged here too, or the packaged app dies on a missing module.
	cp desktop/main.js desktop/preload.js desktop/remote-control.js desktop/package.json "$(WIN_STAGE)/"
	# build/ holds the app icon. electron-builder finds it by convention (buildResources), so
	# leaving it behind doesn't fail the build — it silently ships Electron's default icon.
	cp -r desktop/build "$(WIN_STAGE)/build"
	cp -r frontend/.output/public "$(WIN_STAGE)/web"
	cd "$(WIN_STAGE)" && npm install
	$(MAKE) win-codesign-cache
	# electron-builder directly, not `npm run dist` — the bundle is already staged here, and
	# that script's copy step reaches for a frontend/ that doesn't exist beside it.
	cd "$(WIN_STAGE)" && npm exec -- electron-builder
	@echo ""
	@echo "  Installer -> $(WIN_STAGE)/dist"

# electron-builder fetches a `winCodeSign` bundle before it touches the exe. That bundle is
# not only about signing: it carries **rcedit**, which is what stamps the app icon and version
# info into the executable. Its archive contains two macOS symlinks, and creating a symlink on
# Windows needs a privilege ordinary accounts don't have, so the unpack dies with
#
#     Cannot create symbolic link : A required privilege is not held by the client
#
# and takes the whole build with it — after `dist/win-unpacked/` exists but before the icon is
# applied, which is why the symptom looks like "my icon didn't work" rather than "my build
# failed". The documented cure is Developer Mode or an Administrator shell.
#
# Neither is needed here. The two symlinks are in `darwin/`, which a Windows build never
# touches, so the archive is unpacked once *excluding that directory* into the exact path
# electron-builder looks for. It finds the cache populated and skips the unpack entirely.
WCS_VERSION := winCodeSign-2.6.0
WCS_CACHE := /mnt/c/Users/$(WIN_USER)/AppData/Local/electron-builder/Cache/winCodeSign
WCS_URL := https://github.com/electron-userland/electron-builder-binaries/releases/download/$(WCS_VERSION)/$(WCS_VERSION).7z

win-codesign-cache: ## Seed electron-builder's winCodeSign cache without needing symlink privilege
	@if [ -f "$(WCS_CACHE)/$(WCS_VERSION)/rcedit-x64.exe" ]; then \
		echo "winCodeSign already cached ($(WCS_VERSION))"; \
	else \
		echo "Seeding winCodeSign cache — unpacking $(WCS_VERSION) without its macOS symlinks"; \
		mkdir -p "$(WCS_CACHE)"; \
		curl -fsSL "$(WCS_URL)" -o "$(WCS_CACHE)/$(WCS_VERSION).7z"; \
		printf '@echo off\r\n"%%~1" x -bd -y "-x!darwin" "-x!darwin\\*" -o"%%~2" "%%~3"\r\n' > "$(WIN_STAGE)/unpack-wcs.bat"; \
		cd "$(WIN_STAGE)" && cmd.exe /c unpack-wcs.bat \
			'node_modules\7zip-bin\win\x64\7za.exe' \
			'C:\Users\$(WIN_USER)\AppData\Local\electron-builder\Cache\winCodeSign\$(WCS_VERSION)' \
			'C:\Users\$(WIN_USER)\AppData\Local\electron-builder\Cache\winCodeSign\$(WCS_VERSION).7z'; \
		test -f "$(WCS_CACHE)/$(WCS_VERSION)/rcedit-x64.exe" \
			|| { echo "winCodeSign unpack did not produce rcedit — see APPS.md"; exit 1; }; \
	fi

# Node for the host-side tooling. A Linux node if there is one; otherwise the Windows node
# this machine has, invoked by path. Windows *npm* can't be used against the WSL filesystem
# (cmd.exe has no UNC working directory, so it lands in C:\Windows), but Windows *node* runs
# a script at a WSL path perfectly well — so the Capacitor CLI is invoked directly rather
# than through an npm script.
NODE ?= $(shell command -v node 2>/dev/null || echo '/mnt/c/Program Files/nodejs/node.exe')
CAP = node_modules/@capacitor/cli/bin/capacitor

app-mobile: app-bundle ## Sync the generated bundle into the iOS/Android projects
	@test -d mobile/node_modules || { \
		echo "mobile/node_modules is missing. Install it first:"; \
		echo "    cd mobile && npm install"; \
		exit 1; }
	cd mobile && "$(NODE)" scripts/copy-web.mjs && "$(NODE)" $(CAP) sync

# Building the Android app runs into the same wall as the desktop one: Gradle here would be
# `gradlew.bat` under cmd.exe, which has no UNC working directory, and the SDK's build tools
# (aapt2, d8) are Windows executables that a Linux Gradle couldn't run anyway. So the project
# is staged onto the Windows disk and built there, and the APK is copied back.
#
# The *whole* mobile project is staged, not just `android/`. Capacitor's generated
# capacitor.settings.gradle points each plugin at `../node_modules/@capacitor/<x>/android`, so
# an android/ directory on its own resolves those four Gradle projects to nothing that exists
# and the build dies on "No matching variant ... No variants exist".
WIN_STAGE_MOBILE ?= /mnt/c/Users/$(WIN_USER)/side-chat-android
WIN_STAGE_ANDROID = $(WIN_STAGE_MOBILE)/android
# A shell probe rather than $(wildcard ...): these paths contain spaces, and wildcard splits
# its argument on whitespace — "/mnt/c/Program Files/..." comes back as "/mnt/c/Program".
firstdir = $(shell for d in $(1); do [ -d "$$d" ] && echo "$$d" && break; done)

ANDROID_SDK ?= $(call firstdir,"/mnt/c/Users/$(WIN_USER)/AppData/Local/Android/Sdk" "/mnt/c/Android/Sdk" "$(HOME)/Android/Sdk")
ANDROID_JDK ?= $(call firstdir,"/mnt/c/Program Files/Android/Android Studio/jbr" "/mnt/c/Program Files/Java/jdk-21" "/mnt/c/Program Files/Java/jdk-17")
# Debug by default: signed with the throwaway debug key, installable on your own device.
# `make app-apk GRADLE_TASK=assembleRelease` needs a keystore configured in app/build.gradle.
GRADLE_TASK ?= assembleDebug
# What the finished APK is called. Gradle names it after the module and variant — `app-debug.apk`
# — which says nothing to whoever is sent it. One build produces one APK (the staging dir is
# rebuilt each time), so it gets one name.
APK_NAME ?= SideChat-beta.apk

# Not a dependency on app-mobile: the toolchain is checked first, so a machine without an SDK
# finds out in a second rather than after a full bundle and sync.
app-apk: ## Build an Android APK → mobile/dist (Windows SDK + JDK)
	@test -n "$(ANDROID_SDK)" || { \
		echo "No Android SDK found. Install Android Studio (it ships the SDK), or point at one:"; \
		echo "    make app-apk ANDROID_SDK=/mnt/c/path/to/Sdk"; \
		exit 1; }
	@test -n "$(ANDROID_JDK)" || { \
		echo "No JDK found. Install Android Studio, or point at one:"; \
		echo "    make app-apk ANDROID_JDK='/mnt/c/Program Files/Java/jdk-17'"; \
		exit 1; }
	@echo "SDK: $(ANDROID_SDK)"
	@echo "JDK: $(ANDROID_JDK)"
	$(MAKE) app-mobile
	rm -rf "$(WIN_STAGE_MOBILE)"
	mkdir -p "$(WIN_STAGE_MOBILE)"
	# ios/ and www/ are left behind: neither is any use to Gradle, and the web assets it does
	# need were already copied into android/app/src/main/assets by `cap sync`.
	cp -r mobile/android mobile/node_modules mobile/package.json mobile/capacitor.config.ts "$(WIN_STAGE_MOBILE)/"
	# Gradle reads both of these as Windows paths, with backslashes escaped for .properties.
	printf 'sdk.dir=%s\n' "$$(wslpath -w '$(ANDROID_SDK)' | sed 's/\\/\\\\/g; s/:/\\:/')" \
		> "$(WIN_STAGE_ANDROID)/local.properties"
	printf 'org.gradle.java.home=%s\n' "$$(wslpath -w '$(ANDROID_JDK)' | sed 's/\\/\\\\/g; s/:/\\:/')" \
		>> "$(WIN_STAGE_ANDROID)/gradle.properties"
	cd "$(WIN_STAGE_ANDROID)" && cmd.exe /c "gradlew.bat $(GRADLE_TASK)"
	mkdir -p mobile/dist
	@apk=$$(find "$(WIN_STAGE_ANDROID)/app/build/outputs" -name '*.apk' -print -quit); \
	test -n "$$apk" || { echo "Gradle produced no APK."; exit 1; }; \
	cp "$$apk" "mobile/dist/$(APK_NAME)"
	@echo ""
	@echo "  APK -> mobile/dist/$(APK_NAME)"

ICONS := frontend/.icons
ANDROID_RES := mobile/android/app/src/main/res

verify-mic: ## Measure the mic noise suppressor (dB of noise removed vs dB of speech lost)
	$(COMPOSE) exec -T frontend node /app/scripts/verify-mic-suppression.mjs

icons: ## Regenerate every app icon from frontend/public/brand/icon-source.png
	$(COMPOSE) exec -T frontend sh -c 'npm i --no-save --silent --prefix /tmp/imgtool sharp && node /app/scripts/gen-icons.mjs'
	@# The container only has frontend/ mounted, so the desktop and mobile shells are served
	@# from here rather than written to directly.
	cp $(ICONS)/favicon.ico $(ICONS)/apple-touch-icon.png $(ICONS)/icon-192.png $(ICONS)/icon-512.png frontend/public/
	cp $(ICONS)/logo.png frontend/public/brand/logo.png
	mkdir -p desktop/build
	cp $(ICONS)/icon-1024.png desktop/build/icon.png
	cp $(ICONS)/icon-1024.png mobile/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png
	@for d in mdpi hdpi xhdpi xxhdpi xxxhdpi; do \
	  for n in ic_launcher ic_launcher_round ic_launcher_foreground; do \
	    cp $(ICONS)/android-$$d-$$n.png $(ANDROID_RES)/mipmap-$$d/$$n.png; \
	  done; \
	done
	$(COMPOSE) exec -T frontend rm -rf /app/.icons
	@echo ""
	@echo "  Icons regenerated. They are committed artefacts — review the diff and commit."

octane-build: ## Build the opt-in Octane images (FrankenPHP worker + Swoole)
	$(COMPOSE) build app
	$(COMPOSE) --profile octane build

octane-up: octane-build ## Start the Octane worker-mode variants (frankenphp :8001, swoole :8002)
	$(COMPOSE) --profile octane up -d app-octane-frankenphp app-octane-swoole
	@echo ""
	@echo "  FrankenPHP worker -> http://localhost:$${OCTANE_FRANKENPHP_PORT:-8001}/api/ping"
	@echo "  Swoole            -> http://localhost:$${OCTANE_SWOOLE_PORT:-8002}/api/ping"
	@echo "  Classic (php-fpm-like) still on http://localhost:$${APP_PORT:-8000}"

octane-down: ## Stop and remove the Octane variants (classic app is untouched)
	$(COMPOSE) --profile octane rm -sf app-octane-frankenphp app-octane-swoole

octane-logs: ## Tail logs for the Octane variants
	$(COMPOSE) --profile octane logs -f --tail=100 app-octane-frankenphp app-octane-swoole
