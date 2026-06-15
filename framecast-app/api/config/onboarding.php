<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Sample project clone-on-signup (B7)
    |--------------------------------------------------------------------------
    | When set to a finished project's id, every brand-new workspace gets a
    | deep clone of it as a ready-to-remix starter (see SampleProjectCloner).
    | Leave empty/0 to disable the feature entirely (no clone on signup).
    */
    'sample_project_id' => (int) env('ONBOARDING_SAMPLE_PROJECT_ID', 0),
];
