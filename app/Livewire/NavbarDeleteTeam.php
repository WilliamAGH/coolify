<?php

namespace App\Livewire;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class NavbarDeleteTeam extends Component
{
    use AuthorizesRequests;

    public $team;

    public function mount()
    {
        $this->team = currentTeam()->name;
    }

    public function delete($password, $selectedActions = [])
    {
        if (! verifyPasswordConfirmation($password, $this)) {
            return 'The provided password is incorrect.';
        }

        $currentTeam = currentTeam();
        $this->authorize('delete', $currentTeam);

        $currentTeam->members()->get()->each(function ($user) use ($currentTeam) {
            if ($user->id === Auth::id()) {
                return;
            }
            $currentTeam->detachMember($user);
            $session = DB::table('sessions')->where('user_id', $user->id)->first();
            if ($session) {
                DB::table('sessions')->where('id', $session->id)->delete();
            }
        });
        $currentTeam->delete();

        refreshSession();

        return redirectRoute($this, 'team.index');
    }

    public function render()
    {
        return view('livewire.navbar-delete-team');
    }
}
