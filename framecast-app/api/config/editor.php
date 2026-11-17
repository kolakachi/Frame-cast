<?php

/*
|--------------------------------------------------------------------------
| Which video editor a person opens
|--------------------------------------------------------------------------
|
| The redesigned editor ships next to the classic one (owner, 2026-10-29):
|   • staff first: only internal accounts can open it while `new_open` is off;
|   • once it opens, accounts created at or after `new_users_from` start in
|     the new editor, everyone older stays in classic and can try the new one;
|   • a person's own choice (users.preferences_json.editor) always wins;
|   • classic retires once every existing user has accepted the new one.
|
| Set EDITOR_NEW_USERS_FROM to the moment the opening deploy goes out
| (ISO 8601). Left empty, nobody defaults to the new editor.
|
*/

return [

    // Open to everyone to try (owner, 2026-11-17): classic still opens by default, the new one is a choice.
    'new_open' => (bool) env('EDITOR_NEW_OPEN', true),

    'new_users_from' => env('EDITOR_NEW_USERS_FROM'),

];
