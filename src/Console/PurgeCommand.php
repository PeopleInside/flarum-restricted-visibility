<?php

namespace PeopleInside\RestrictedVisibility\Console;

use PeopleInside\RestrictedVisibility\Constants;
use PeopleInside\RestrictedVisibility\Purge\Purger;
use Flarum\Console\AbstractCommand;
use Flarum\Group\Group;
use Flarum\User\User;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;

class PurgeCommand extends AbstractCommand
{
    public function __construct(protected Purger $purger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(Constants::ID.':purge')
            ->setDescription('Elimina restrizioni, permessi e impostazioni di Restricted Visibility.')
            ->addOption('mode', 'm', InputOption::VALUE_REQUIRED, 'Cosa fare dei contenuti riservati: hide | delete | public', 'hide')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, "ID dell'utente registrato come autore del nascondimento (predefinito: primo amministratore)")
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mostra solo il riepilogo, senza modificare nulla')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Non chiedere conferma (per script)');
    }

    protected function fire(): int
    {
        $mode = (string) $this->input->getOption('mode');
        if (! in_array($mode, Constants::MODES, true)) {
            $this->output->writeln('<error>Modalità non valida. Usa: hide, delete o public.</error>');

            return self::FAILURE;
        }

        $p = $this->purger->preview();
        $this->output->writeln(sprintf(
            "Contenuti riservati: %d discussioni, %d post. Collegamenti: %d gruppi, %d utenti.\nModalità: <info>%s</info>",
            $p['discussions'], $p['posts'], $p['groupLinks'], $p['userLinks'], $mode
        ));

        if ($this->input->getOption('dry-run')) {
            $this->output->writeln('Dry-run: nessuna modifica effettuata.');

            return self::SUCCESS;
        }

        if ($mode === 'public') {
            $this->output->writeln('<comment>ATTENZIONE: i contenuti riservati diventeranno visibili a tutti.</comment>');
        } elseif ($mode === 'delete') {
            $this->output->writeln('<comment>ATTENZIONE: i contenuti riservati saranno ELIMINATI in modo definitivo.</comment>');
        }
        $this->output->writeln('Operazione irreversibile. Fai prima un backup del database.');

        if (! $this->input->getOption('yes')) {
            if (! $this->input->isInteractive()) {
                $this->output->writeln('<error>Terminale non interattivo: usa --yes per confermare.</error>');

                return self::FAILURE;
            }
            $answer = $this->getHelper('question')->ask(
                $this->input, $this->output,
                new Question(sprintf('Digita %s per procedere: ', Constants::CONFIRM_WORD))
            );
            if (! is_string($answer) || ! hash_equals(Constants::CONFIRM_WORD, trim($answer))) {
                $this->output->writeln('Annullato.');

                return self::FAILURE;
            }
        }

        $actor = $this->resolveActor();
        $report = $this->purger->purge($mode, $actor);

        $this->output->writeln(sprintf(
            '<info>Fatto.</info> Discussioni interessate: %d, post interessati: %d.',
            $report['affectedDiscussions'], $report['affectedPosts']
        ));
        $this->output->writeln('Ora disattiva l\'estensione, poi usa "Elimina dati" dal pannello admin (o migrate:reset) per rimuovere anche le tabelle.');

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $id = $this->input->getOption('actor');
        if ($id !== null && ctype_digit((string) $id)) {
            return User::find((int) $id);
        }

        return User::query()->whereHas('groups', fn ($q) => $q->where('groups.id', Group::ADMINISTRATOR_ID))->orderBy('id')->first();
    }
}
