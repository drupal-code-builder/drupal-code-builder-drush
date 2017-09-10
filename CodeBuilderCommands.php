<?php

namespace Drush\Commands\CodeBuilder;

use Consolidation\AnnotatedCommand\CommandData;
use Drush\Commands\DrushCommands;

/**
 * Provides commands for generating code with the Drupal Code Builder library.
 */
class CodeBuilderCommands extends DrushCommands {

  /**
   * Initialize Drupal Code Builder before a command runs.
   *
   * @hook init @code_builder
   */
  public function initializeLibrary() {
    // Check our library is present.
    if (!class_exists(\DrupalCodeBuilder\Factory::class)) {
      throw new \Exception(dt("Can't find the Drupal Code Builder library. This needs to be installed with composer."));
    }

    // Set up the DCB factory.
    \DrupalCodeBuilder\Factory::setEnvironmentLocalClass('Drush')
      ->setCoreVersionNumber(drush_drupal_version());
  }

  /**
   * Add the 'location' option to our commands.
   *
   * @hook option @code_builder
   * @option location Directory in which to store data. Use a relative path to
   *    store within public://, an absolute path otherwise. Defaults to
   *    public://code-builder. This should typically be set in drushrc.php to
   *    permamently store data in a custom location.
   */
  public function optionLocation($options = ['location' => 'code-builder']) {}

  /**
   * Set the default value for the location option, and set it to Drush context.
   *
   * This is done in an init hook so interact hooks have the location set and
   * thus have access to component data.
   *
   * @hook init @code_builder
   */
  public function initLocationOption(InputInterface $input, AnnotationData $annotationData) {
    $location = $input->getOption('location');

    // Have to set this back into the options context, as otherwise
    // drush_get_option() which the Environment calls won't have it.
    // See https://github.com/drush-ops/drush/issues/2907
    // TODO: Find a better way to pass this to the library.
    // TODO: Stop calling this 'data' in the library.
    drush_set_option('data', $location);
  }

  /**
   * Updates Drupal component definitions.
   *
   * @command cb-update
   * @usage drush cb-update
   *   Update data on Drupal components, storing in the default location.
   * @usage drush cb-update --location=relative/path
   *   Update data on hooks, storing data in public://relative/path.
   * @usage drush cb-update --location=/absolute/path
   *   Update data on hooks, storing data in /absolute/path.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @aliases cbu
   * @code_builder
   */
  public function commandUpdateDefinitions() {
    // Get our task handler. This performs a sanity check which throws an
    // exception.
    $task_handler_collect = $this->getCodeBuilderTask('Collect');

    $task_handler_collect->collectComponentData();

    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    drush_print("Data on hooks, services, and plugin types has been copied to {$hooks_directory} and processed.");

    return TRUE;
  }

  /**
   * Lists stored Drupal component definitions.
   *
   * @command cb-list
   * @option type Which type of data to list. One of:
   *   'all': show everything.
   *   'hooks': show hooks.
   *   'plugins': show plugin types.
   *   'services': show services.
   * @usage drush cb-list
   *   List stored analysis data on Drupal components.
   * @usage drush cb-list --type=plugins
   *   List stored analysis data on Drupal plugin types.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @aliases cbl
   * @code_builder
   */
  public function commandListDefinitions($options = ['type' => 'all']) {
    // Get our task handler, which checks hook data is ready.
    $mb_task_handler_report = $this->getCodeBuilderTask('ReportHookData');

    $type = $options['type'];

    if ($type == 'hooks' || $type == 'all') {
      $data = $mb_task_handler_report->listHookData();

      // TODO -- redo this as a --format option, same as 'drush list'.
      /*
      if (drush_get_option('raw')) {
        drush_print_r($data);
        return;
      }
      */

      // TODO - redo this as a --filter option, same as 'drush list'.
      /*
      if (count($commands)) {
        // Put the requested filenames into the keys of an array, and intersect them
        // with the hook data.
        $files_requested = array_fill_keys($commands, TRUE);
        $data_requested = array_intersect_key($data, $files_requested);
      }
      else {
        $data_requested = $data;
      }

      if (!count($data_requested) && count($files_requested)) {
        drush_print(t("No hooks found for the specified files."));
      }
      */

      drush_print("Hooks:");
      foreach ($data as $file => $hooks) {
        drush_print("Group $file:", 2);
        foreach ($hooks as $key => $hook) {
          drush_print($hook['name'] . ': ' . $hook['description'], 4);
        }
      }

      // List presets.
      $mb_task_handler_report_presets = $this->getCodeBuilderTask('ReportHookPresets');

      $hook_presets = $mb_task_handler_report_presets->getHookPresets();
      foreach ($hook_presets as $hook_preset_name => $hook_preset_data) {
        drush_print("Preset $hook_preset_name: " . $hook_preset_data['label'], 2);
        foreach ($hook_preset_data['hooks'] as $hook) {
          drush_print($hook, 4);
        }
      }
    }

    // TODO: don't need to check version, this is on 8 now.
    if (drush_drupal_major_version() == 8) {
      if ($type == 'plugins' || $type == 'all') {
        $mb_task_handler_report_plugins = $this->getCodeBuilderTask('ReportPluginData');

        $data = $mb_task_handler_report_plugins->listPluginData();

        drush_print("Plugins types:");
        foreach ($data as $plugin_type_id => $plugin_type_data) {
          drush_print($plugin_type_id, 2);
        }
      }

      if ($type == 'services' || $type == 'all') {
        $mb_task_handler_report_services = $this->getCodeBuilderTask('ReportServiceData');

        $data = $mb_task_handler_report_services->listServiceData();

        drush_print("Services:");
        foreach ($data as $service_id => $service_info) {
          drush_print($service_id, 2);
        }
      }
    }

    $time = $mb_task_handler_report->lastUpdatedDate();
    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();
    drush_print(t("Component data retrieved from @dir.", array('@dir' => $hooks_directory)));
    drush_print(t("Component data was processed on @time.", array(
      '@time' => date(DATE_RFC822, $time),
    )));
  }

  /**
   * Gets a Drupal Code Builder Task handler.
   *
   * @param $task_type
   *  The type of task to pass to \DrupalCodeBuilder\Factory::getTask().
   *
   * @return
   *  The task handler.
   *
   * @throws \Exception
   *  Throws an exception if there is a problem that would prevent the task's
   *  operation.
   */
  protected function getCodeBuilderTask($task_type) {
    try {
      $task = \DrupalCodeBuilder\Factory::getTask($task_type);
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      $this->handleSanityException($e);
    }
    return $task;
  }

  /**
   * Re-throws a DCB exception with a message.
   *
   * @param \DrupalCodeBuilder\Exception\SanityException $e
   *  The original exception thrown by the library.
   *
   * @throws \Exception
   *  Throws an exception with a message based on the given DCB exception.
   */
  protected function handleSanityException(\DrupalCodeBuilder\Exception\SanityException $e) {
    $failed_sanity_level = $e->getFailedSanityLevel();
    switch ($failed_sanity_level) {
      case 'data_directory_exists':
        $message = "The component data directory could not be created or is not writable.";
        break;
      case 'component_data_processed':
        $message = "No component data was found. Run 'drush cb-download' to process component data from your site's code files.";
        break;
    }
    throw new \Exception($message);
  }

}
