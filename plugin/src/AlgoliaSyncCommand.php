<?php

namespace Weble\ZOOAlgolia;

use App;
use Item;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AlgoliaSyncCommand extends Command
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'algolia:sync';

    protected function configure()
    {
        $this->setDescription('Import ZOO items into Algolia');
        $this->addOption('app', 'a', InputArgument::OPTIONAL, 'The id of the app to import types from');
        $this->addOption('type', 't', InputArgument::OPTIONAL, 'The type to import');
        $this->addOption('ids', null, InputArgument::OPTIONAL, 'The comma separated list of the ids of the items to import');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        require_once(JPATH_BASE.'/plugins/system/zlframework/config.php');
        require_once JPATH_SITE . '/plugins/system/zooalgolia/vendor/autoload.php';

        $zoo = App::getInstance('zoo');

        if ($input->getOption('app')) {
            $applications = [$zoo->table->application->get($input->getOption('app'))];
        } else {
            /** @var \Application[] $applications */
            $applications = $zoo->table->application->all();
        }

        $type = $input->getOption('type');

        $ids = $input->getOption('ids');
        if ($ids) {
            $ids = array_map('intval', explode(",", $ids));
        }

        foreach ($applications as $application) {
            $algoliaSync = new AlgoliaSync($application);
            if (!$algoliaSync->isConfigured()) {
                continue;
            }

            $output->writeln('Import Items from ' . $application->name);

            /** @var Item[] $items */
            if ($ids) {
                $items = $zoo->table->item->all(['conditions' => 'application_id = ' . $application->id . ' AND id IN ( ' . implode(",", $ids) . ')']);
                $total = count($items);
            } else {
                $items = $zoo->table->item->findAll($application->id);
                $total = $application->getItemCount();
            }


            $progress = new ProgressBar($output, $total);

            foreach ($items as $item) {

                if ($type !== null && $type !== $item->getType()->id) {
                    continue;
                }

                $progress->advance();
                $algoliaSync->sync($item);
            }

            $progress->finish();
        }

        return 0;
    }
}
