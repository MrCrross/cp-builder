<?php

namespace App\Modules\CommercialProposals;

use App\Modules\CommercialProposals\Authorization\ProposalPolicy;
use App\Modules\CommercialProposals\Authorization\ProposalTemplatePolicy;
use App\Modules\CommercialProposals\Infrastructure\Persistence\Proposal;
use App\Modules\CommercialProposals\Infrastructure\Persistence\ProposalTemplate;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class CommercialProposalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Proposal::class, ProposalPolicy::class);
        Gate::policy(ProposalTemplate::class, ProposalTemplatePolicy::class);

        // Лимиты плана §9.1: выпуск/rotation/uploads 10/мин на user+IP.
        RateLimiter::for('proposals.publish', function (Request $request): Limit {
            $userId = $request->user()?->id;

            return Limit::perMinute(10)->by(($userId ?? 'guest').'|'.$request->ip());
        });

        RateLimiter::for('branding.upload', function (Request $request): Limit {
            $userId = $request->user()?->id;

            return Limit::perMinute(10)->by(($userId ?? 'guest').'|'.$request->ip());
        });
    }
}
