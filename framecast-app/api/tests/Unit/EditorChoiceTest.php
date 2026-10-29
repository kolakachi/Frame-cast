<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\EditorChoice;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Classic or the redesigned editor: staff first, then new accounts, a person's own pick always wins (2026-10-29). */
class EditorChoiceTest extends TestCase
{
    private function user(string $email, string $created, ?string $pick = null, string $role = 'owner'): User
    {
        $u = new User(['email' => $email, 'role' => $role, 'preferences_json' => $pick ? ['editor' => $pick] : []]);
        $u->created_at = Carbon::parse($created);

        return $u;
    }

    public function test_staff_only_while_closed(): void
    {
        config(['editor.new_open' => false, 'editor.new_users_from' => '2026-10-01T00:00:00Z']);

        $customer = $this->user('a@shop.test', '2026-10-20', 'new');
        $this->assertFalse(EditorChoice::available($customer));
        $this->assertSame('classic', EditorChoice::for($customer), 'a saved pick does not open it early');

        $staff = $this->user('k@wyvstudio.com', '2026-01-01');
        $this->assertTrue(EditorChoice::available($staff));
        $this->assertSame('classic', EditorChoice::for($staff), 'staff start in classic and switch to try it');
        $this->assertSame('new', EditorChoice::for($this->user('k@wyvstudio.com', '2026-01-01', 'new')));
        $this->assertTrue(EditorChoice::available($this->user('x@gmail.com', '2026-01-01', null, 'super_admin')));
    }

    public function test_once_open_new_accounts_start_in_the_new_editor(): void
    {
        config(['editor.new_open' => true, 'editor.new_users_from' => '2026-11-10T12:00:00Z']);

        $this->assertSame('classic', EditorChoice::for($this->user('old@shop.test', '2026-11-10 11:59:59')), 'older accounts keep classic');
        $this->assertSame('new', EditorChoice::for($this->user('new@shop.test', '2026-11-10 12:00:00')));
        $this->assertSame('classic', EditorChoice::for($this->user('new@shop.test', '2026-11-12', 'classic')), 'switching back sticks');
        $this->assertSame('new', EditorChoice::for($this->user('old@shop.test', '2026-01-01', 'new')), 'an old account can move over');
    }

    public function test_no_start_date_means_nobody_defaults_to_new(): void
    {
        config(['editor.new_open' => true, 'editor.new_users_from' => null]);

        $this->assertSame('classic', EditorChoice::for($this->user('new@shop.test', '2026-12-01')));
    }
}
