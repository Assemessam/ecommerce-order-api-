<?php

namespace App\Console\Commands;

use App\Services\Auth\RoleProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class GrantRole extends Command
{
    protected $signature = 'roles:grant {email : Email of an existing locally registered user} {role : Canonical internal role}';

    protected $description = 'Grant an internal role to an existing user in the local environment only';

    /**
     * Execute the console command.
     */
    public function handle(RoleProvisioningService $roles): int
    {
        try {
            $roles->grantRole($this->argument('email'), $this->argument('role'));
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('Register the user locally before granting an internal role.');

            return self::FAILURE;
        }

        $this->info('Role granted. No password or token was created or changed.');

        return self::SUCCESS;
    }
}
