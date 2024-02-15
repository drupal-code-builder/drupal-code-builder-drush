<?php

namespace DrupalCodeBuilderDrush\Drush\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Drush\Commands\DrushCommands;
use Drush\Boot\DrupalBootLevels;
use Drush\Drush;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides developer commands for the Drupal Code Builder library.
 */
class CodeBuilderDevDrushCommands extends DrushCommands {

  /**
   * Updates Drupal component definitions stored as sample data for testing.
   *
   * @command cb-update-test
   * @usage drush cb-update-test
   *   Update data on Drupal components, storing in the test sample data
   *   location.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @hidden
   * @aliases cbut
   * @code_builder
   */
  public function commandUpdateDefinitions(OutputInterface $output) {
    $drupal_root = Drush::bootstrapManager()->getRoot();
    $drupal_version = Drush::bootstrap()->getVersion($drupal_root);

    \DrupalCodeBuilder\Factory::setEnvironmentLocalClass('WriteTestsSampleLocation')
      ->setCoreVersionNumber($drupal_version);

    $task_handler_collect = \DrupalCodeBuilder\Factory::getTask('Testing\CollectTesting');

    $job_list = $task_handler_collect->getJobList();

    $results = [];
    $this->io()->progressStart(count($job_list));
    foreach ($job_list as $job) {
      $task_handler_collect->collectComponentDataIncremental([$job], $results);
      $this->io()->progressAdvance(1);
    }
    $this->io()->progressFinish();

    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    $output->writeln("Data on hooks, services, and plugin types has been copied to {$hooks_directory} and processed.");

    return TRUE;
  }

  /**
   * Outputs the data for a single collect job.
   *
   * TODO: Make this a bit nicer - needs a numeric argument!
   */
  #[\Drush\Attributes\Command(name: 'cb-update-devel', aliases: ['cbud'])]
  #[\Drush\Attributes\Argument(name: 'job', description: 'Numeric key of the collect job to process in the job list array.')]
  #[\Drush\Attributes\Help(hidden: true)]
  #[\Drush\Attributes\Bootstrap(level: DrupalBootLevels::FULL)]
  public function commandTestCollect(OutputInterface $output, int $job) {
    $drupal_root = Drush::bootstrapManager()->getRoot();
    $drupal_version = Drush::bootstrap()->getVersion($drupal_root);

    \DrupalCodeBuilder\Factory::setEnvironmentLocalClass('Drush')
      ->setCoreVersionNumber($drupal_version);

    $task_handler_collect = \DrupalCodeBuilder\Factory::getTask('Testing\CollectTesting');

    $job_list = $task_handler_collect->getJobList();

    if (!isset($job_list[$job])) {
      throw new \InvalidArgumentException("Job $job not found.");
    }

    // Get the helper from the DCB container.
    $collector_helper = \DrupalCodeBuilder\Factory::getContainer()->get($job_list[$job]['collector']);
    $job_data = $collector_helper->collect([$job_list[$job]]);

    dump($job_data);

    return TRUE;
  }

}
