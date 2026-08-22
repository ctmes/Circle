<?php

namespace App\Console\Commands;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Provisions an organisation and attaches people to it.
 *
 * Organisation setup is an administrative act, not a self-service API call —
 * the spec's API surface (§15) deliberately starts at the Circle. Note that
 * organisation membership grants no Circle access on its own; it only permits
 * creating Circles within the organisation.
 */
class ProvisionOrganisation extends Command
{
    protected $signature = 'circle:provision-org
                            {name : Organisation display name}
                            {--user=* : email:Name:password triples to create or attach}
                            {--external=* : emails to mark as external collaborators}';

    protected $description = 'Create an organisation and attach member accounts';

    public function handle(): int
    {
        $name = $this->argument('name');

        $organisation = Organisation::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name],
        );

        $this->info("Organisation: {$organisation->name} ({$organisation->id})");

        $external = array_map('strtolower', $this->option('external'));

        foreach ($this->option('user') as $spec) {
            [$email, $displayName, $password] = array_pad(explode(':', $spec, 3), 3, null);

            if ($email === null || $displayName === null || $password === null) {
                $this->error("Skipping malformed --user value: {$spec}");

                continue;
            }

            $user = User::firstOrCreate(
                ['email' => strtolower($email)],
                ['name' => $displayName, 'password' => $password],
            );

            OrganisationMembership::firstOrCreate(
                ['organisation_id' => $organisation->id, 'user_id' => $user->id],
                ['org_role' => 'member', 'is_external' => in_array(strtolower($email), $external, true)],
            );

            $this->line(sprintf('  %-34s %s', $user->email, $user->id));
        }

        return self::SUCCESS;
    }
}
