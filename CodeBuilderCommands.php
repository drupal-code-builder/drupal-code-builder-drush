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
   * @hook pre-command @code_builder
   */
  public function preCommandLocationOption(CommandData $commandData) {
    $location = $commandData->input()->getOption('location');

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
    try {
      $task_handler_collect = \DrupalCodeBuilder\Factory::getTask('Collect');

      // Hidden option for developers: downloads a subset of hooks to create the
      // data for Drupal Code Builder's unit tests.
      if (drush_get_option('test')) {
        $task_handler_collect = \DrupalCodeBuilder\Factory::getTask('Testing\CollectTesting');
      }
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      $this->handleSanityException($e);
      // TODO!
      throw $e;
    }

    $task_handler_collect->collectComponentData();

    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    drush_print("Data on hooks, services, and plugin types has been copied to {$hooks_directory} and processed.");

    return TRUE;
  }

  /**
   * Lists stored Drupal component definitions.
   *
   * @command cb-list
   * @usage drush cb-list
   *   List stored analysis data on Drupal components.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @aliases cbl
   * @code_builder
   */
  public function commandListDefinitions() {
    // Get our task handler, which checks hook data is ready.
    try {
      $mb_task_handler_report = \DrupalCodeBuilder\Factory::getTask('ReportHookData');
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      module_builder_handle_sanity_exception($e);
      return;
    }

    $time = $mb_task_handler_report->lastUpdatedDate();
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
    try {
      $mb_task_handler_report_presets = \DrupalCodeBuilder\Factory::getTask('ReportHookPresets');
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      module_builder_handle_sanity_exception($e);
      return;
    }

    $hook_presets = $mb_task_handler_report_presets->getHookPresets();
    foreach ($hook_presets as $hook_preset_name => $hook_preset_data) {
      drush_print("Preset $hook_preset_name: " . $hook_preset_data['label'], 2);
      foreach ($hook_preset_data['hooks'] as $hook) {
        drush_print($hook, 4);
      }
    }

    // TODO: don't need to check version, this is on 8 now.
    if (drush_drupal_major_version() == 8) {
      try {
        $mb_task_handler_report_plugins = \DrupalCodeBuilder\Factory::getTask('ReportPluginData');
      }
      catch (\DrupalCodeBuilder\Exception\SanityException $e) {
        module_builder_handle_sanity_exception($e);
        return;
      }

      $data = $mb_task_handler_report_plugins->listPluginData();

      drush_print("Plugins types:");
      foreach ($data as $plugin_type_id => $plugin_type_data) {
        drush_print($plugin_type_id, 2);
      }

      try {
        $mb_task_handler_report_services = \DrupalCodeBuilder\Factory::getTask('ReportServiceData');
      }
      catch (\DrupalCodeBuilder\Exception\SanityException $e) {
        module_builder_handle_sanity_exception($e);
        return;
      }

      $data = $mb_task_handler_report_services->listServiceData();

      drush_print("Services:");
      foreach ($data as $service_id => $service_info) {
        drush_print($service_id, 2);
      }
    }

    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();
    drush_print(t("Component data retrieved from @dir.", array('@dir' => $hooks_directory)));
    drush_print(t("Component data was processed on @time.", array(
      '@time' => date(DATE_RFC822, $time),
    )));
  }

  protected function handleSanityException() {
    // TODO!
  }

}
