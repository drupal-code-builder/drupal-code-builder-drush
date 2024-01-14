<?php

namespace DrupalCodeBuilderDrush\Drush\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Drush\Commands\DrushCommands;
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

    $task_handler_collect->collectComponentData();

    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    $output->writeln("Data on hooks, services, and plugin types has been copied to {$hooks_directory} and processed.");

    return TRUE;
  }

}
