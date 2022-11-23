<?php

namespace Weble\ZOOAlgolia;

use App;
use Item;
use Joomla\Console\Command\AbstractCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AlgoliaSyncCommandJ4 extends AbstractCommand
{
    protected static $defaultName = 'algolia:sync';

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Import ZOO items into Algolia');
        $this->addOption('app', 'a', InputArgument::OPTIONAL, 'The id of the app to import types from');
        $this->addOption('type', 't', InputArgument::OPTIONAL, 'The type to import');
        $this->addOption('ids', null, InputArgument::OPTIONAL, 'The comma separated list of the ids of the items to import');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (file_exists(JPATH_ADMINISTRATOR."/components/com_zoo/config.php")) {
            require_once JPATH_ADMINISTRATOR."/components/com_zoo/config.php";
        }

        if (file_exists(JPATH_ROOT . '/plugins/system/zlframework/config.php')) {
            require_once JPATH_ROOT . '/plugins/system/zlframework/config.php';
        }

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
            foreach ($application->getTypes() as $app_type) {

                if ($type !== null && $type !== $app_type->identifier) {
                    continue;
                }

                $algoliaSync = new AlgoliaSync($app_type);

                if (!$algoliaSync->isConfigured()) {
                    continue;
                }
                $output->writeln("\nImport Items from " . $application->name . " of type " . $app_type->getName());

                /** @var Item[] $items */
                if ($ids) {
                    $items = $zoo->table->item->all(['conditions' => 'type = ' . $app_type->identifier . ' AND id IN ( ' . implode(",", $ids) . ')']);
                    $total = count($items);
                } else {
                    //$items = $zoo->table->item->findAll($application->id);
                    $items =  $zoo->table->item->getByType($app_type->identifier, $application->id);
                    $total = count($items);
                }

                $progress = new ProgressBar($output, $total);

                foreach ($items as $item) {

                    $progress->advance();
                    $algoliaSync->sync($item);
                }

                $progress->finish();

            }

        }

        return 0;
    }
}
