<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Auth\Contracts\ParticipantOtpSender;
use App\Modules\Auth\Services\LaravelParticipantOtpSender;
use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Cabanas\Observers\EventoServicioReservaObserver;
use App\Modules\Cabanas\Policies\CabanaPolicy;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Clubs\Policies\ClubPolicy;
use App\Modules\Clubs\Policies\PersonaPolicy;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoServicioReserva;
use App\Modules\Events\Policies\EventPolicy;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Policies\OrganizacionPolicy;
use App\Modules\Terrains\Models\Terreno;
use App\Modules\Terrains\Policies\TerrenoPolicy;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Policies\RolePolicy;
use App\Modules\Users\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ParticipantOtpSender::class, LaravelParticipantOtpSender::class);
    }

    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Event::class, EventPolicy::class);
        Gate::policy(Club::class, ClubPolicy::class);
        Gate::policy(Persona::class, PersonaPolicy::class);
        Gate::policy(Organizacion::class, OrganizacionPolicy::class);
        Gate::policy(Terreno::class, TerrenoPolicy::class);
        Gate::policy(Cabana::class, CabanaPolicy::class);
        EventoServicioReserva::observe(EventoServicioReservaObserver::class);

        Gate::before(function (User $user, ?string $ability = null) {
            return $user->isSuperAdmin() ? true : null;
        });

        Password::defaults(function () {
            return Password::min(6)
                ->max(64)
                ->symbols()
                ->rules(['regex:/[A-Z]/']);
        });
    }
}
