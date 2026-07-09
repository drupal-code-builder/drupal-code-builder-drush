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
   */
  #[\Drush\Attributes\Command(name: 'cb-update-devel', aliases: ['cbud'])]
  #[\Drush\Attributes\Argument(name: 'collector_name', description: 'The short class name of the collector helper task. Omit for a prompt.')]
  #[\Drush\Attributes\Argument(name: 'job', description: 'An idenfitier of the collect job to process in the job list array. Omit for a prompt.')]
  #[\Drush\Attributes\Help(hidden: true)]
  #[\Drush\Attributes\Bootstrap(level: DrupalBootLevels::FULL)]
  public function commandTestCollect(OutputInterface $output, string $collector_name = NULL, string $job = NULL) {
    $drupal_root = Drush::bootstrapManager()->getRoot();
    $drupal_version = Drush::bootstrap()->getVersion($drupal_root);

    \DrupalCodeBuilder\Factory::setEnvironmentLocalClass('Drush')
      ->setCoreVersionNumber($drupal_version);

    // Get the Collect task, and hack out of it the list of Collect task
    // helpers.
    $collect = \DrupalCodeBuilder\Factory::getTask('Collect');
    $collect_reflection = new \ReflectionClass($collect);
    $collectors_reflection = $collect_reflection->getProperty('collectors');
    $collectors = $collectors_reflection->getvalue($collect);
    // Remove the special metadata collector.
    unset($collectors['Collect\MetadataCollector']);

    // Make an array of options, short class name => Task name.
    $collectors_names = array_keys($collectors);
    $collectors_options = array_combine($collectors_names, array_map(fn ($name) => explode('\\', $name)[1], $collectors_names));

    if ($collector_name) {
      // If a collector was specified, try matching it to a task name,
      // and then a short class name.
      if (!isset($collectors[$collector_name])) {
        $collector_name = array_search($collector_name, $collectors_options);
        if (!$collector_name) {
          throw new \Exception("Bad collector name.");
        }
      }
    }
    else {
      // If no collector was specified, ask for one.
      $collector_name = $this->io()->select('Select collector', $collectors_options, required: TRUE, scroll: count($collectors_options));
    }

    $task_handler_collect = $collectors[$collector_name];
    $job_list = $task_handler_collect->getJobList();

    // If the collector returns a job list, filter it down, either with the
    // given job parameter, or by asking the user.
    if ($job_list) {
      // The values in jobs are different for each collector. Assume that
      // the first key is a reasonably useful (and unique!) value for the UI!
      $job_list_values = array_map(fn ($job_item) => $job_item[array_key_first($job_item)], $job_list);
      $job_list_lookup = array_flip($job_list_values);

      if (!$job) {
        $job_list_options = array_combine($job_list_values, $job_list_values);

        // WTF, you can't get a numeric index back from a numeric options array.
        $job = $this->io()->select('Select collector', $job_list_options, required: TRUE, scroll: count($job_list_options));
      }

      $job_index = $job_list_lookup[$job];
      $job_list = [$job_index => $job_list[$job_index]];
    }

    $data = $task_handler_collect->collect($job_list);

    dump($data);

    return TRUE;
  }

}
