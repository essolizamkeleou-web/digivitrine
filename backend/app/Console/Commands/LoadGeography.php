<?php

namespace App\Console\Commands;

use App\Services\GeographyLoader;
use Illuminate\Console\Command;

class LoadGeography extends Command
{
    protected $signature = 'geography:load
                            {--apply : Write to the database (default: simulation only)}
                            {--path= : Directory of the data files (default: database/data/geography)}';

    protected $description = 'Charge le referentiel geographique du Grand Lome (simulation par defaut, --apply pour ecrire)';

    public function handle(GeographyLoader $loader): int
    {
        $apply = (bool) $this->option('apply');
        $path = $this->option('path') ?: null;
        $result = $apply ? $loader->apply($path) : $loader->plan($path);

        foreach ($result['warnings'] as $warning) {
            $this->warn('AVERTISSEMENT : '.$warning);
        }

        foreach ($result['errors'] as $error) {
            $this->error('ERREUR : '.$error);
        }

        if ($result['errors'] !== [] || $result['plan'] === null) {
            $this->error('Aucune ecriture : corriger les erreurs ci-dessus.');

            return self::FAILURE;
        }

        $plan = $result['plan'];
        $communesToCreate = count(array_filter($plan['communes'], fn (array $c) => ! $c['exists']));
        $toCreate = count(array_filter($plan['neighborhoods'], fn (array $n) => $n['state'] === 'create'));
        $unverified = count(array_filter($plan['neighborhoods'], fn (array $n) => $n['state'] === 'create' && $n['verification'] !== 'verifie'));

        $this->line('Communes : '.count($plan['communes']).' dans les fichiers, '.$communesToCreate.' a creer.');
        $this->line('Quartiers : '.count($plan['neighborhoods']).' dans les fichiers, '.$toCreate.' a creer, '.($toCreate - $unverified).' actifs et '.$unverified.' inactifs (non verifies).');

        if (! $apply) {
            $this->info("SIMULATION : rien n'a ete ecrit. Relancer avec --apply pour ecrire.");

            return self::SUCCESS;
        }

        $created = $result['created'];
        $this->info("Ecrit : {$created['communes']} commune(s), {$created['neighborhoods']} quartier(s), {$created['aliases']} alias.");
        $this->line("Rappel : aucune liste n'est presumee exhaustive ; les quartiers non verifies restent inactifs.");

        return self::SUCCESS;
    }
}
