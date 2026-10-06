<?php

namespace App\Console\Commands;

use App\Services\Auth\AdministratorProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

class GrantAdministrator extends Command
{
    protected $signature = 'admin:grant {email : Email of an existing locally registered user}';

    protected $description = 'Grant administrator privileges to an existing user in the local environment only';

    public function handle(AdministratorProvisioningService $administrators): int
    {
        try {
            $administrators->grantAdministrator($this->argument('email'));
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (ModelNotFoundException) {
            $this->error('Register the user locally before granting administrator privileges.');

            return self::FAILURE;
        }

        $this->info('Administrator privileges granted. No password or token was created or changed.');

        return self::SUCCESS;
    }
}
