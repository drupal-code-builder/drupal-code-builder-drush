<?php

namespace Drush\Commands\CodeBuilder;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use Drush\Commands\DrushCommands;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Provides commands for generating code with the Drupal Code Builder library.
 */
class CodeBuilderCommands extends DrushCommands {

  protected $extensions = [];

  protected $module_names = [];

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
   * Add the 'data-location' option to our commands.
   *
   * @hook option @code_builder
   * @option data-location Directory in which to store data. Use a relative path
   *    to store within public://, an absolute path otherwise. Defaults to
   *    public://code-builder. This should typically be set in drushrc.php to
   *    permamently store data in a custom location.
   */
  public function optionDataLocation($options = ['data-location' => 'code-builder']) {}

  /**
   * Sets a default for the data-location option, and set in Drush context.
   *
   * This is done in an init hook so interact hooks have the location set and
   * thus have access to component data.
   *
   * @hook init @code_builder
   */
  public function initDataLocationOption(InputInterface $input, AnnotationData $annotationData) {
    $location = $input->getOption('data-location');

    // Have to set this back into the options context, as otherwise
    // drush_get_option() which the Environment calls won't have it.
    // See https://github.com/drush-ops/drush/issues/2907
    // TODO: Find a better way to pass this to the library.
    // TODO: Stop calling this 'data' in the library.
    drush_set_option('data', $location);
  }

  /**
   * Build a Drupal component.
   *
   * @command cb-module
   * @param string $module_name The module name. If this is a '.', the module at
   *    the current location is used. Will be prompted for if omitted.
   * @param string $component_type The component type. Will be prompted for if
   *    omitted, which allows entering multiple values to build more than one
   *    component.
   * @option parent The directory in which to create a new module. Defaults to
   *    'modules/custom', or 'modules' if the 'custom' subdirectory doesn't
   *    exist. A '.' means the current location. This option is ignored if the
   *    module already exists.
   * @option dry-run If specified, no files are written.
   * @usage drush cb-module
   *    Build a Drupal component for a module, with interactive prompt.
   * @usage drush cb-module .
   *    Build a Drupal component for the module at the current location, with
   *    interactive prompt.
   * @usage drush cb-module my_module module
   *    Build the basic module 'my_module'.
   * @usage drush cb-update my_module plugins
   *    Add plugins to the module 'my_module'. If the module doesn't exist, it
   *    will be created.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @aliases cbm
   * @code_builder
   */
  public function commandBuildComponent(
    InputInterface $input,
    OutputInterface $output,
    $module_name,
    $component_type,
    $options = [
       // Our default is complicated.
      'parent' => NULL,
      'dry-run' => FALSE,
    ]
  ) {
    // Interactive mode is required, bail otherwise.
    if (!$input->isInteractive()) {
      throw new \Exception("The cb-module command must be run in interactive mode.");
    }

    try {
      $task_handler_generate = \DrupalCodeBuilder\Factory::getTask('Generate', 'module');
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      $this->handleSanityException($e);
    }

    $existing_module_files = $this->getModuleFiles($module_name);

    $build_values = drush_get_context('build_values');
    //dump($build_values);

    $files = $task_handler_generate->generateComponent($build_values, $existing_module_files);
    //dump($files);

    $base_path = $this->getComponentFolder('module', $module_name, $options['parent']);
    $this->outputComponentFiles($output, $base_path, $files, $options['dry-run']);
  }

  /**
   * Set the module name to the current directory if not provided.
   *
   * @hook init cb-module
   */
  public function initializeBuildComponent(InputInterface $input, AnnotationData $annotationData) {
    $module_name = $input->getArgument('module_name');
    if ($module_name == '.') {
      // If no module name is given, or it's the special value '.', take the
      // current directory to be the module name parameter.
      $current_directory = drush_cwd();
      $module_name = basename($current_directory);

      // TODO: output a message to say this is what we've done.

      // Later validation will check this is actually a module.
      $input->setArgument('module_name', $module_name);
    }
  }

  /**
   * Get the component type if not provided.
   *
   * @hook interact cb-module
   */
  public function interactBuildComponent(InputInterface $input, OutputInterface $output, AnnotationData $annotationData) {
    // Get the generator task.
    try {
      $task_handler_generate = \DrupalCodeBuilder\Factory::getTask('Generate', 'module');
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      $this->handleSanityException($e);
    }
    $component_data_info = $task_handler_generate->getRootComponentDataInfo();

    // Remove hook presets, barely relevant in Drupal 8.
    unset($component_data_info['module_hook_presets']);

    // Initialize an array of values.
    $build_values = [];

    // Get the module name if not provided.
    if (empty($input->getArgument('module_name'))) {
      $module_names = $this->getModuleNames();

      $question = new \Symfony\Component\Console\Question\Question('Enter the name of the module to either create or add to', 'my_module');
      $question->setAutocompleterValues($module_names);

      $value = $this->io()->askQuestion($question);
      $input->setArgument('module_name', $value);
    }

    // Determine whether the given module name is for an existing module.
    $module_name = $input->getArgument('module_name');
    $module_exists = $this->moduleExists($module_name);

    $subcomponent_property_names = $this->getSubComponentPropertyNames($component_data_info);

    // Get the component type if not provided.
    if (empty($input->getArgument('component_type'))) {
      $options = [];

      // If the module doesn't exist yet, first option is to just build its
      // basics.
      if (!$module_exists) {
        $options['module'] = 'Module only';
      }

      foreach ($subcomponent_property_names as $property_name) {
        // TODO: some of these labels are plurals! Should they be?
        $options[$property_name] = $component_data_info[$property_name]['label'];
      }

      if ($module_exists) {
        $prompt = dt("This module already exists. Choose component types to add to it");
      }
      else {
        $prompt = dt("This module doesn't exist. Choose component types to start it with");
      }

      // Use numerical keys in the options given to the user, same as
      // DrushStyle::choice().
      $option_values = array_values($options);

      $question = new \Symfony\Component\Console\Question\ChoiceQuestion(
        $prompt,
        $option_values
      );
      $question->setMultiselect(TRUE);

      $component_types_values = $this->io()->askQuestion($question);
      $component_types = array_intersect($options, $component_types_values);
      $component_types = array_keys($component_types);

      // Set any old value on the $component_type command parameter, so the
      // command thinks that it's now set.
      $input->setArgument('component_type', 'made-up!');
    }
    else {
      // TODO: validate component type if supplied

      $component_types[] = $input->getArgument('component_type');
    }

    // TODO: validate module if supplied

    // Initialize component data with the base type and root name.
    $build_values = [];
    $build_values['base'] = 'module';
    $build_values['root_name'] = $module_name;

    // Mark the properties we don't want to prompt for.
    $properties_to_skip = [];
    if ($component_types === ['module']) {
      // Don't prompt for any of the subcomponents.
      $properties_to_skip = $subcomponent_property_names;
    }
    else {
      if ($module_exists) {
        // Don't prompt for anything that is *not* a subcomponent, as we won't
        // be building the basic module.
        $properties_to_skip = array_diff(array_keys($component_data_info), $subcomponent_property_names);;
      }

      // Mark the subcomponents that weren't selected.
      $properties_to_skip = array_merge($properties_to_skip, array_diff($subcomponent_property_names, $component_types));
    }
    // Skip the root_name, since we have already got it.
    $properties_to_skip[] = 'root_name';

    foreach ($properties_to_skip as $property_name) {
      $component_data_info[$property_name]['skip'] = TRUE;
    }

    // Collect data for the properties.
    // TODO: boolean components will get asked for, even thought there's no
    // point since they've been selected.
    $build_values = $this->interactCollectProperties($task_handler_generate, $output, $component_data_info, $build_values);

    // Set the values on the context, so the comand callback can get them.
    // TODO: This is a hack because it's not possible to define dynamic
    // arguments.
    // See https://github.com/consolidation/annotated-command/issues/115
    drush_set_context('build_values', $build_values);
  }

  /**
   * Filters a data info array to get subcomponents.
   *
   * @param $component_data_info
   *  A data info array.
   *
   * @return
   *  An array of the property names which are subcomponents.
   */
  protected function getSubComponentPropertyNames($component_data_info) {
    // Get the properties of a component which are themselves components.
    $return = [];

    // Cheat and for now consider hooks a subcomponent, even though they're
    // a simple property that then produces the Hooks component.
    $return[] = 'hooks';

    foreach ($component_data_info as $property_name => $property_info) {
      if (isset($property_info['component'])) {
        $return[] = $property_name;
      }
    }

    return $return;
  }

  /**
   * Interactively collects values for a data properties info array.
   *
   * This recurses into itself for compound properties.
   *
   * @param $task_handler_generate
   *  The generate task handler.
   * @param &$data_info
   *  A data info array about properties to collect. This should not have been
   *  passed to prepareComponentDataProperty() already.
   * @param $values
   *  A values array.
   *
   * @return
   *  The values array with the user-entered values added to it.
   */
  protected function interactCollectProperties($task_handler_generate, $output, &$data_info, $values) {
    static $nesting = 0;

    $nesting++;

    foreach ($data_info as $property_name => &$property_info) {
      if (!empty($property_info['skip'])) {
        // TODO! prepare it so it gets defaults!
        continue;
      }

      if ($property_info['format'] == 'compound') {
        // Compound property: collect multiple items, recursing into this
        // method for each item.
        // Treat top-level compound properties as required, since the user
        // selected them in the initial component menu, so should not be asked
        // again.
        if ($data_info[$property_name]['required'] || $nesting == 1) {
          $output->writeln("Enter details for {$data_info[$property_name]['label']} (at least one required):");
        }
        else {
          $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            dt("Enter details for {$data_info[$property_name]['label']}?"),
            FALSE
          );
          $enter_compound = $this->io()->askQuestion($question);

          if (!$enter_compound) {
            continue;
          }
        }

        $value = [];
        $delta = 0;
        do {
          // Initialize a new child item so a default value can be placed
          // into it.
          $value[$delta] = [];

          $value[$delta] = $this->interactCollectProperties($task_handler_generate, $output, $data_info[$property_name]['properties'], $value[$delta]);

          $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            dt("Enter more {$data_info[$property_name]['label']}?"),
            FALSE
          );
          $enter_another = $this->io()->askQuestion($question);

          // Increase the delta for the next loop.
          $delta++;
        }
        while ($enter_another == TRUE);

        $values[$property_name] = $value;
      }
      else {
        // Simple property.
        $task_handler_generate->prepareComponentDataProperty($property_name, $property_info, $values);

        $default = $values[$property_name];

        $value = $this->askQuestionForProperty($property_info, $default);

        $values[$property_name] = $value;
      }
    }

    return $values;
  }

  /**
   * Ask the user a question for a single property.
   *
   * TODO: make this return the Question object instead?
   *
   * @param $property_info
   *   The property info array. It should already have been run through the
   *   generator task's prepareComponentDataProperty().
   * @param $default
   *   The default value.
   *
   * @return
   *   The user-entered value, or the default if nothing is given.
   */
  protected function askQuestionForProperty($property_info, $default) {
    //dump('askQuestionForProperty');
    //dump($property_info);
    // TODO: convert this to a child class of Symfony Question.

    // DCB might give us a default of an array for properties that expect an
    // array, but Symfony wants a string.
    if ($default === []) {
      $default = '';
    }

    if (isset($property_info['options'])) {
      // Question with options, either string or array format.
      $options = $property_info['options'];

      // Non-required properties need a 'none' option, as Symfony won't
      // accept an empty value.
      if (!$property_info['required']) {
        $options = array_merge(['none' => 'None'], $options) ;

        $default = 'none';
      }

      $question = new \Symfony\Component\Console\Question\ChoiceQuestion(
        dt("Enter the {$property_info['label']}"),
        $options,
        $default
      );

      if ($property_info['format'] == 'array') {
        // TODO: bug in symfony, autocomplete only works on the first value in
        // a multiselect question.
        $question->setMultiselect(TRUE);
      }

      // Note that this bypasses DrushStyle::choice()'s override, which
      // converts the options to be keyed numerically, thus hiding the machine
      // name. Good or bad thing for us?
      $value = $this->io()->askQuestion($question);

      // Get rid of the 'none' value if that was the default.
      if ($value === ['none']) {
        $value = [];
      }
      // Symfony appears to give us an array for a multiselect question, which
      // what we want.
    }
    elseif ($property_info['format'] == 'array') {
      // Array without options to choose from.
      // TODO: consider adding the explanation message on its own line first --
      // but need to work out how to format it, in the face of nonexistent
      // documentation in Symfony code.
      do {
        $question = new \Symfony\Component\Console\Question\Question(
          dt("Enter the {$property_info['label']}, one per line, empty line to finish"),
          $default
        );
        // Hack to work around the question not allowing an empty answer.
        // See https://github.com/drush-ops/drush/issues/2931
        $question->setValidator(function ($answer) { return $answer; });

        $single_value = $this->io()->askQuestion($question);

        if (!empty($single_value)) {
          $value[] = $single_value;
        }
      }
      while (!empty($single_value));

      return $value;
    }
    elseif ($property_info['format'] == 'boolean') {
      // Boolean property.
      $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
        dt("Do you want a {$property_info['label']}"),
        $default
      );
      // Note that booleans are always required: you have to answer either TRUE
      // or FALSE.

      $value = $this->io()->askQuestion($question);
    }
    elseif ($property_info['format'] == 'string') {
      // String property.
      $question = new \Symfony\Component\Console\Question\Question(
        dt("Enter the {$property_info['label']}"),
        $default
      );

      if (!$property_info['required']) {
        // Hack to work around the question not allowing an empty answer.
        // See https://github.com/drush-ops/drush/issues/2931
        $question->setValidator(function ($answer) { return $answer; });
      }

      $value = $this->io()->askQuestion($question);
    }
    else {
      // TODO: use the machine name rather than the label!
      throw new \Exception("Unable to ask question for property " . $property_info['label']);
    }

    return $value;
  }

  /**
   * @hook validate cb-module
   */
  public function validateBuildComponent(CommandData $commandData) {
    $input = $commandData->input();

    // Validate the module name.
    $module_name = $input->getArgument('module_name');

    // If the module doesn't already exist, ensure it's a valid machine name.
    // return new CommandError("Invalid module name $module_name.");

    // TODO: validate the component if given on the command line.
  }

  /**
   * Returns the names of all modules in the current site, enabled or not.
   *
   * @return string[]
   *   An array of module machine names.
   */
  protected function getModuleNames() {
    return array_keys($this->getModuleList());
  }

  /**
   * Determines whether a module with the given name exists.
   *
   * @param string $module_name
   *  A module name.
   *
   * @return bool
   *   TRUE if the module exists (enabled or not). FALSE if it does not.
   */
  protected function moduleExists($module_name) {
    $module_files = $this->getModuleList();
    return isset($module_files[$module_name]);
  }

  /**
   * Returns a list of all modules in the current site, enabled or not.
   *
   * @return string[]
   *   An array whose keys are module names and whose values are the relative
   *   paths to the .info.yml files.
   */
  protected function getModuleList() {
    // The state service keeps a static cache, no need for us to do too.
    $system_module_files = \Drupal::state()->get('system.module.files', []);
    return $system_module_files;
  }

  /**
   * Returns extension objects for modules in the current site, enabled or not.
   *
   * @return \Drupal\Core\Extension\Extension[]
   *   An array of extension objects.
   */
  protected function getModules() {
    if (empty($this->extensions)) {
      $listing = new \Drupal\Core\Extension\ExtensionDiscovery(\Drupal::root());
      $this->extensions = $listing->scan('module');
    }

    return $this->extensions;
  }

  /**
   * Gets a list of a module's files.
   *
   * @param string $module_name
   *  A module name.
   *
   * @return string[]
   *   An array whose keys are pathnames relative to the module folder, and
   *   whose values are the absolute pathnames. If the module doesn't exist, the
   *   array is emtpy.
   */
  protected function getModuleFiles($module_name) {
    $module_files = $this->getModuleList();
    if (isset($module_files[$module_name])) {
      $module_path = dirname($module_files[$module_name]);
      $module_files = [];


      $finder = new \Symfony\Component\Finder\Finder();
      $finder->files()->in($module_path);

      foreach ($finder as $file) {
        $module_files[$file->getRelativePathname()] = $file->getRealPath();
      }

      return $module_files;
    }
    else {
      return [];
    }
  }

  /**
   * Output generated text, to terminal or to file.
   *
   * @param OutputInterface $output
   *  The output.
   * @param $component_dir
   *  The base folder for the component. May or may not exist.
   * @param $filename
   *  The array of files to write. Keys are filenames relative to the
   *  $component_dir, values are strings for the file contents.
   * @param $dry_run
   *  Whether this is a dry run, i.e. files should not be written.
   */
  protected function outputComponentFiles(OutputInterface $output, $component_dir, $files, $dry_run) {
    $quiet = drush_get_context('DRUSH_QUIET');

    // Determine whether to output to terminal.
    $output_to_terminal = !$quiet;

    if ($output_to_terminal) {
      foreach ($files as $filename => $code) {
        // TODO: styling!
        $output->writeln("Proposed $filename:");
        $output->write($code);
      }
    }

    // Determine whether to write files.
    $write_files = !$dry_run;

    // If we're not writing files, we're done.
    if (!$write_files) {
      return;
    }

    $files_exist = [];
    foreach ($files as $filename => $code) {
      $filepath = $component_dir . '/' . $filename;
      // TODO: add option for handling this:
      // - prompt for overwrite
      // - check git status before overwrite and overwrite if git clean
      // - force overwrite
      if (file_exists($filepath)) {
        $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
          dt('File ' . $filename . ' exists. Overwrite this file?'),
          FALSE
        );
        $overwrite = $this->io()->askQuestion($question);

        if (!$overwrite) {
          continue;
        }
      }

      // Because the filename part can contain subdirectories, check these exist
      // too.
      $subdir = dirname($filepath);
      if (!is_dir($subdir)) {
        $result = mkdir($subdir, 0777, TRUE);
        if ($result && !$quiet) {
          if ($subdir == $component_dir) {
            $output->writeln("Module directory $component_dir created");
          }
          else {
            $output->writeln("Module subdirectory $subdir created");
          }
        }
      }

      // Add to file option.
      // If the file doesn't exist, we skip this and silently write it anyway.
      // TODO: add to file option is a bit broken these days anyway.
      /*
      if (drush_get_option('add') && file_exists($filepath)) {
        $fh = fopen($filepath, 'a');
        fwrite($fh, $code);
        fclose($fh);
        continue;
      }
      */

      file_put_contents($filepath, $code);
    }
  }

  /**
   * Get the folder where a generated component's files should be written to.
   *
   * @param $component_type
   *  The type of the component. One of 'module' or 'theme'.
   * @param $component_name
   *  The component name.
   * @param $parent_dir
   *  The 'parent' option from the command.
   *
   * @return
   *  The full system path for the component's folder, without a trailing slash.
   */
  protected function getComponentFolder($component_type, $component_name, $parent_dir) {
    $drupal_root = drush_get_context('DRUSH_DRUPAL_ROOT');

    // First try: if the component exists, we write there: nice and simple.
    // In Drupal 8, drupal_get_filename() triggers an error for a component that
    // doesn't exist, so bypass that with a dummy error handler.
    // TODO! Fix this hack!
    set_error_handler(function() {}, E_USER_WARNING);

    $component_path = @drupal_get_path($component_type, $component_name);

    restore_error_handler();

    if (!empty($component_path)) {
      return $drupal_root . '/' . $component_path;
    }

    // Third try: 'parent' option was given.
    if (!empty($parent_dir)) {
      // The --parent option allows the user to specify a location for the new
      // module folder.
      if (substr($parent_dir, 0 , 1) == '.') {
        // An initial . means to start from the current directory rather than
        // the modules folder, which allows submodules to be created where the
        // user is standing.
        $module_dir = drush_cwd() . '/';
        // Remove both the . and the following /.
        $parent_dir = substr($parent_dir, 2);
        if ($parent_dir) {
          // If there's anything left (since just '.' is a valid option),
          // append it.
          $module_dir .= $parent_dir;
        }
        if (substr($module_dir, -1) != '/') {
          // Append a final '/' in case the terminal autocomplete didn't.
          $module_dir .= '/';
        }
      }
      else {
        // If there's no dot, assume that an existing module is meant.
        // (Would anyone enter a complete path for this??? If we do need this,
        // then consider recursing into this for the parent path??)
        $module_dir .= drupal_get_path($component_type, $parent_dir) . '/';
      }
      return $module_dir . $component_name;
    }

    // Fourth and final try: build it based on the module folder structure.
    $possible_folders = [
      '/modules/custom',
      '/modules',
    ];
    foreach ($possible_folders as $folder) {
      if (is_dir($drupal_root . $folder)) {
        return $drupal_root . $folder . '/' . $component_name;
      }
    }
  }

  /**
   * Updates Drupal component definitions.
   *
   * @command cb-update
   * @usage drush cb-update
   *   Update data on Drupal components, storing in the default location.
   * @usage drush cb-update --data-location=relative/path
   *   Update data on hooks, storing data in public://relative/path.
   * @usage drush cb-update --data-location=/absolute/path
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
