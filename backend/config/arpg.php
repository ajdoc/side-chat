<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Skills borrowed from other jobs
    |--------------------------------------------------------------------------
    |
    | A hero may learn skills outside their own line — a swordsman who picked up
    | a healing prayer, a necromancer who can shoot. This caps how many *distinct*
    | foreign skills one hero may hold, which is what keeps a class a class: with
    | no cap, every hero converges on the same best six and the roster is cosmetic.
    |
    | The cap is **per tier**, not one pool. Advancing to a second job opens a
    | fresh allowance of borrowed second-job skills, so the choice you made at
    | level 10 doesn't silently spend the choice you get at level 30. A third
    | tier, when it exists, is one more entry in this list.
    |
    | Raising a borrowed skill's level costs points like any other and doesn't
    | count again — the cap is on breadth, not on investment.
    |
    */

    'foreign_skill_limits' => [
        1 => (int) env('ARPG_FOREIGN_SKILL_LIMIT', 3),
        2 => (int) env('ARPG_FOREIGN_SKILL_LIMIT_T2', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Job advancement
    |--------------------------------------------------------------------------
    |
    | What level each tier opens at. A hero advances to their second job — mage to
    | wizard, thief to assassin — on reaching this, which is also the gate on
    | learning *any* second-tier skill, borrowed or not.
    |
    */

    'advancement_levels' => [
        2 => (int) env('ARPG_SECOND_JOB_LEVEL', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Skill levels
    |--------------------------------------------------------------------------
    |
    | How far a single skill can be pushed, and how many points a hero earns per
    | character level to push them with.
    |
    */

    'max_skill_level' => (int) env('ARPG_MAX_SKILL_LEVEL', 10),

    'skill_points_per_level' => (int) env('ARPG_SKILL_POINTS_PER_LEVEL', 1),

];
