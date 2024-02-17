<?php

namespace DrupalCodeBuilderDrush\Drush\Commands;

use Consolidation\AnnotatedCommand\AnnotationData;
use Consolidation\AnnotatedCommand\CommandData;
use Consolidation\AnnotatedCommand\CommandError;
use DrupalCodeBuilder\Definition\DeferredGeneratorDefinition;
use DrupalCodeBuilder\Definition\MergingGeneratorDefinition;
use DrupalCodeBuilderDrush\Environment\DrushModuleBuilderDevel;
use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Exceptions\CommandFailedException;
use MutableTypedData\Data\DataItem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;
use Robo\Contract\ConfigAwareInterface;
use Robo\Common\ConfigAwareTrait;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\text;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\suggest;

/**
 * Provides commands for generating code with the Drupal Code Builder library.
 */
class CodeBuilderDrushCommands extends DrushCommands implements ConfigAwareInterface {

  use ConfigAwareTrait;

  protected $extensions = [];

  protected $module_names = [];

  protected $buildValues = [];

  /**
   * Initialize Drupal Code Builder before a command runs.
   *
   * This needs to be a post-init hook rather than pre-init because non-module
   * commands don't get their bookstrap level taken into account in the init
   * hook -- see https://github.com/drush-ops/drush/issues/3058.
   *
   * @hook post-init @code_builder
   */
  public function initializeLibrary() {
    $drupal_root = Drush::bootstrapManager()->getRoot();
    $drupal_version = Drush::bootstrap()->getVersion($drupal_root);

    // Set up the DCB factory.
    // Ensure compatibility with module_builder_devel, which if enabled uses a
    // differnet format for the stored analysis data.
    if (\Drupal::moduleHandler()->moduleExists('module_builder_devel')) {
      $environment = new DrushModuleBuilderDevel();

      \DrupalCodeBuilder\Factory::setEnvironment($environment)
        ->setCoreVersionNumber($drupal_version);
    }
    else {
      \DrupalCodeBuilder\Factory::setEnvironmentLocalClass('Drush')
        ->setCoreVersionNumber($drupal_version);
    }
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
    // drush_set_option('data', $location);
  }

  /**
   * Generate code to add to or create a Drupal module.
   *
   * @command cb:module
   *
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
   * @usage drush cb:module
   *    Build a Drupal component for a module, with interactive prompt.
   * @usage drush cb:module .
   *    Build a Drupal component for the module at the current location, with
   *    interactive prompt.
   * @usage drush cb:module my_module module
   *    Build the basic module 'my_module'.
   * @usage drush cb:module my_module plugins
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
      throw new CommandFailedException("The cb:module command must be run in interactive mode.");
    }

    try {
      $task_handler_generate = \DrupalCodeBuilder\Factory::getTask('Generate', 'module');
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      // Show a message and end with failure.
      $this->io()->error($this->getSanityLevelMessage($e));

      return Command::FAILURE;
    }

    $existing_module_files = $this->getModuleFiles($module_name);

    /** @var \MutableTypedData\Data\DataItem */
    $component_data = $this->componentData;

    $files = $task_handler_generate->generateComponent($component_data, $existing_module_files);
    //dump($files);

    $base_path = $this->getComponentFolder('module', $module_name, $options['parent']);
    $this->outputComponentFiles($output, $base_path, $files, $options['dry-run']);
  }

  /**
   * Set the module name to the current directory if not provided.
   *
   * @hook init cb:module
   */
  public function initializeBuildComponent(InputInterface $input, AnnotationData $annotationData) {
    $module_name = $input->getArgument('module_name');
    if ($module_name == '.') {
      // If no module name is given, or it's the special value '.', take the
      // current directory to be the module name parameter.
      $current_directory = $this->getConfig()->get('env.cwd');

      $module_name = basename($current_directory);

      // TODO: check that this is actually a module?

      // Output a message to say this is what we've done.
      $this->io()->text("Working module set to {$module_name}.");

      // Later validation will check this is actually a module.
      $input->setArgument('module_name', $module_name);
    }
  }

  /**
   * Get the component type if not provided.
   *
   * @hook interact cb:module
   */
  public function interactBuildComponent(InputInterface $input, OutputInterface $output, AnnotationData $annotationData) {
    // Get the generator task.
    try {
      $task_handler_generate = \DrupalCodeBuilder\Factory::getTask('Generate', 'module');
    }
    catch (\DrupalCodeBuilder\Exception\SanityException $e) {
      // Set the required arguments to dummy value to silence a complaint about
      // them.
      $input->setArgument('module_name', 'made-up!');
      $input->setArgument('component_type', 'made-up!');

      return Command::FAILURE;
    }

    /** @var \MutableTypedData\Data\DataItem */
    $component_data = $task_handler_generate->getRootComponentData();

    // Initialize an array of values.
    $build_values = [];

    // Get the module name if not provided.
    if (empty($input->getArgument('module_name'))) {

      $module_name = suggest(
        label: 'Enter the name of an existing module to add to it, or a new module name to create it',
        required: true,
        // This doesn't work as well as with Symfony -- you have to backspace
        // to clear it :(
        // default: 'my_module',
        options: function ($input) {
          $module_names = $this->getModuleNames();

          return preg_grep("@$input@", $module_names);
        },
      );

      $input->setArgument('module_name', $module_name);
    }

    // Determine whether the given module name is for an existing module.
    $module_name = $input->getArgument('module_name');
    $module_exists = $this->moduleExists($module_name);

    $subcomponent_property_names = $this->getSubComponentPropertyNames($component_data);

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
        $options[$property_name] = $component_data->{$property_name}->getLabel();
      }

      if ($module_exists) {
        $prompt = dt("This module already exists. Choose component types to add to it");
      }
      else {
        $prompt = dt("This module doesn't exist. Choose component types to start it with");
      }

      $component_types = multiselect(
        label: $prompt,
        options: $options,
        // Show the lot.
        scroll: count($options),
      );

      // Set any old value on the $component_type command parameter, so the
      // command thinks that it's now set.
      $input->setArgument('component_type', 'made-up!');
    }
    else {
      // TODO: validate component type if supplied

      $component_types[] = $input->getArgument('component_type');
    }

    // TODO: validate module if supplied

    // TODO Initialize component data with the base type and root name.
    $component_data->root_name = $module_name;

    // Mark the properties we don't want to prompt for.
    $properties_to_skip = [];
    if ($component_types === ['module']) {
      // Don't prompt for any of the subcomponents.
      $properties_to_skip = $subcomponent_property_names;

      // TODO -- whole different pathway, just collect the non-complex.
    }
    else {
      if ($module_exists) {
        // Don't prompt for anything that is *not* a subcomponent, as we won't
        // be building the basic module.
        $properties_to_skip = array_diff($component_data->getPropertyNames(), $subcomponent_property_names);;
      }

      // Mark the subcomponents that weren't selected.
      $properties_to_skip = array_merge($properties_to_skip, array_diff($subcomponent_property_names, $component_types));
    }
    // Skip the root_name, since we have already got it.
    $properties_to_skip[] = 'root_name';

    // Set things to internal so the use is not prompted for them.
    $component_data->root_name->setInternal(TRUE);

    foreach ($properties_to_skip as $property_name) {
      $component_data->{$property_name}->setInternal(TRUE);

      // ARRGH babysit the annoying MTD bug with single-valued complex data
      // getting instantiated once you look at it!
      $component_data->removeItem($property_name);
    }

    // Collect data for the requested components.
    $breadcrumb = [];
    $this->interactCollectProperties($component_data, $breadcrumb);

    // Set the data on this class, so the comand callback can get them.
    // TODO: This is a hack because it's not possible to define dynamic
    // arguments.
    // See https://github.com/consolidation/annotated-command/issues/115
    // TODO: The above TODO is ancient -- see if it's still relevant.
    $this->componentData = $component_data;
    $this->buildValues = $build_values;
  }

  /**
   * Gets the properties from a data item which we count as subcomponents.
   *
   * @param $component_data
   *  The component data.
   *
   * @return
   *  An array of the property names which are subcomponents.
   */
  protected function getSubComponentPropertyNames(DataItem $component_data) {
    foreach ($component_data as $property_name => $property_data) {
      if ($property_data->isComplex()) {
        $return[] = $property_name;
        continue;
      }
      // Bit of a hack: non-complex properties that request generators.
      if (in_array(get_class($property_data->getDefinition()), [MergingGeneratorDefinition::class, DeferredGeneratorDefinition::class])) {
        $return[] = $property_name;
        continue;
      }
    }

    // Total hack: hooks.
    // TODO: This won't work when we support other root component types!
    $return[] = 'hooks';

    return $return;
  }

  /**
   * Interactively collects values for a data item.
   *
   * This recurses into itself for complex properties.
   *
   * @param DataItem $data
   *  THe data item.
   * @param $breadcrumb
   *  An array of the component labels forming a trail into the component data
   *  hierarchy to the current point. This is output each time focus moves to a
   *  different component (inculding back out to one we've been to before) in
   *  order to help users keep track of what they are entering.
   *
   * @return
   *  The values array with the user-entered values added to it.
   */
  protected function interactCollectProperties(DataItem $data, $breadcrumb): void {
    $address = $data->getAddress();
    $level = substr_count($address, ':');
    $breadcrumb[] = $data->getLabel();

    // Show breadcrumb, but not on the first level.
    // This helps to give the user an overview of where they are in the data.
    if ($level > 1) {
      $this->outputDataBreadcrumb('Current item', $breadcrumb);
    }

    if ($data->isComplex()) {
      // At the top-level, we know the user requested this because of the
      // initial component selection.
      if ($level > 1) {
        $enter_complex = confirm(
          label: "Enter details for {$data->getLabel()}?",
          default: FALSE,
        );
        if (!$enter_complex) {
          return;
        }
      }

      if ($data->isMultiple()) {
        // Multi-valued complex data.
        $delta = 0;
        do {
          $delta_item = $data->createItem();

          // Add to the breadcrumb to pass into the recursion.
          $delta_breadcrumb = $breadcrumb;
          // Use human-friendly index.
          $breadcrumb_index = $delta + 1;
          $delta_breadcrumb[] = "Item {$breadcrumb_index}";

          foreach ($delta_item as $data_item) {
            $this->interactCollectProperties($data_item, $delta_breadcrumb);
          }

          // Increase the delta for the next loop and the cardinality check.
          // (TODO: Cardinality check but probably YAGNI.)
          $delta++;

          $enter_another = confirm(
            label: dt("Enter more {$data->getLabel()} items?"),
            default: FALSE,
          );
        }
        while ($enter_another == TRUE);
      }
      else {
        // Single-valued complex data.
        foreach ($data as $data_item) {
          $this->interactCollectProperties($data_item, $breadcrumb);
        }
      }
    }
    else {
      // Simple data.
      if ($data->getType() == 'boolean') {
        // Boolean.
        $data->applyDefault();

        $value = confirm(
          label: dt("Add a {$data->getLabel()}?"),
          // TODO: setting this to $data->isRequired() is weird -- are some
          // booleans set to required when FALSE is an OK answer?
          required: FALSE,
          default: $data->value,
        );

        $data->set($value);
      }
      elseif ($data->hasOptions()) {
        // Options, either single or multiple.
        if (count($data->getOptions()) > 20) {
          // Large option set.
          $options_callback = function (string $value) use ($data) {
            $options = $data->getOptions();
            $option_keys = array_keys($options);

            // Escape regex characters and the delimiters.
            $pattern = preg_quote($value, '@');
            // Match case-insensitively, to make it easier to work with event name
            // constants.
            $regex = '@' . $pattern . '@i';
            // Allow the '_' and '.' characters to be used interchangeably.
            // The '_' MUST go first in the $search array, as if '\.' goes first, then
            // the underscores in the $replace string will get found in the second
            // pass.
            $regex = str_replace(['_', '\.'], '[._]', $regex);
            $matched_keys = preg_grep($regex, $option_keys);

            $results = [];
            if (!$data->isRequired()) {
              // Can't be an empty string, WTF.
              $results[' '] = '-- None --';
            }
            foreach ($matched_keys as $key) {
              $results[$key] = $key . ' - ' . $options[$key]->getLabel();
            }

            return $results;
          };

          if ($data->isMultiple()) {
            $value = multisearch(
              label: 'Enter the ' . $data->getLabel(),
              options: $options_callback,
              required: $data->isRequired(),
            );

            $data->set($value);
          }
          else {
            $value = search(
              label: 'Enter the ' . $data->getLabel(),
              options: $options_callback,
            );

            // Babysit stupid empty value.
            if ($value != ' ') {
              $data->set($value);
            }
          }
        }
        else {
          // Small option set.
          $options = [];

          // Have to babysit single-option non-required :(
          if (!$data->isMultiple() && !$data->isRequired()) {
            $options[''] = 'None';
          }

          foreach ($data->getOptions() as $value => $option) {
            $options[$value] = $option->getLabel();
          }

          if ($data->isMultiple()) {
            $value = multiselect(
              label: 'Enter the ' . $data->getLabel(),
              options: $options,
              required: $data->isRequired(),
            );

            $data->set($value);
          }
          else {
            $value = select(
              label: 'Enter the ' . $data->getLabel(),
              options: $options,
            );
          }

          $data->set($value);
        }
      }
      else {
        // Text value.
        if ($data->isMultiple()) {
          $value = text(
            label: "Enter the {$data->getLabel()} as a comma-separated list of values",
            required: $data->isRequired(),
          );

          // TODO: trim!
          $value = explode(',', $value);

          $data->set($value);
        }
        else {
          $data->applyDefault();

          $value = text(
            label: "Enter the {$data->getLabel()}",
            required: $data->isRequired(),
            default: $data->value ?? '',
          );

          if (!empty($value)) {
            $data->set($value);
          }
        }
      }
    }

    return;

    // TODO: mine old code for things I've not converted yet!

    // Show breadcrumb, but not on the first level.
    // This helps to give the user an overview of where they are in the data.
    if (count($breadcrumb) > 1) {
      $this->outputDataBreadcrumb($output, 'Current item', $breadcrumb);
    }

    // Get the name of the first property, so we can put that in the breadcrumb
    // in case we recurse further. The first property of any component is
    // typically some sort of ID or name for it.
    $first_property_name = reset(array_keys($data_info));

    foreach ($data_info as $property_name => &$property_info) {
      if (!empty($property_info['skip'])) {
        // TODO! prepare it so it gets defaults!
        continue;
      }

      $task_handler_generate->prepareComponentDataProperty($property_name, $property_info, $values);

      // Show a breadcrumb for the first property after exiting a compound
      // property, that is, when coming out of a nesting level.
      if (!empty($breadcrumb_left_nesting)) {
        $this->outputDataBreadcrumb($output, 'Back to', $breadcrumb);

        $breadcrumb_left_nesting = FALSE;
      }

      if ($property_info['format'] == 'compound') {
        // Compound property: collect multiple items, recursing into this
        // method for each item.
        // Treat top-level compound properties as required, since the user
        // selected them in the initial component menu, so should not be asked
        // again.
        if ($data_info[$property_name]['required'] || count($breadcrumb) == 1) {
          $output->writeln("Enter details for {$data_info[$property_name]['label']} (at least one required):");
        }
        else {
          $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            dt("Enter details for {$data_info[$property_name]['label']}?"),
            FALSE
          );
          $enter_compound = $this->io()->askQuestion($question);

          if (!$enter_compound) {
            // Zap any defaults the prepare step might have put in, so that if
            // the user has said they don't want anything here, there's
            // actually nothing here.
            $values[$property_name] = [];

            // Move on to the next property.
            continue;
          }
        }

        $nested_breadcrumb = $breadcrumb;
        $nested_breadcrumb[] = $property_info['label'];

        $value = [];
        $cardinality = $property_info['cardinality'] ?? -1;
        $delta = 0;
        do {
          // Initialize a new child item so a default value can be placed
          // into it, or take a default if one already exists for this item.
          $value[$delta] = $values[$property_name][$delta] ?? [];

          // Add to the breadcrumb to pass into the recursion.
          $item_breadcrumb = $nested_breadcrumb;
          // Don't show a delta if the cardinality is 1.
          if ($cardinality != 1) {
            // Use human-friendly index.
            $breadcrumb_delta = $delta + 1;
            $item_breadcrumb[] = "Item {$breadcrumb_delta}";
          }

          $value[$delta] = $this->interactCollectProperties(
            $task_handler_generate,
            $output,
            $data_info->{$property_name},
            $item_breadcrumb
          );

          // Increase the delta for the next loop and the cardinality check.
          $delta++;

          if ($delta != $cardinality) {
            $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
              dt("Enter more {$data_info[$property_name]['label']}?"),
              FALSE
            );
            $enter_another = $this->io()->askQuestion($question);
          }
          else {
            // Reached maximum cardinality: loop must end.
            $enter_another = FALSE;
          }
        }
        while ($enter_another == TRUE);

        // Mark that we've just exited a level of nesting for the breadcrumb.
        $breadcrumb_left_nesting = TRUE;

        $values[$property_name] = $value;
      }
      else {
        // Simple property.

        // Special case for top-level boolean: the user has already effectively
        // stated the value for this is TRUE, when selecting it in the initial
        // menu.
        if ($property_info['format'] == 'boolean' && count($breadcrumb) == 1) {
          $values[$property_name] = TRUE;
          continue;
        }

        $default = $values[$property_name];

        $value = $this->askQuestionForProperty($property_info, $default);

        $values[$property_name] = $value;

        // For the first property (which should be non-compound), take the
        // value and put it into the breadcrumb, so further output of the
        // breadcrumb that includes this level has a name rather than just
        // 'item DELTA'.
        if ($property_name == $first_property_name) {
          array_pop($breadcrumb);
          // TODO: prefix this with the label? But we don't have that at this
          // point!
          $breadcrumb[] = $value;
        }
      }
    }
  }

  /**
   * Output a breadcrumb, showing where the user is in the data structure.
   *
   * @param string $label
   *   A label describing the breadcrumb.
   * @param string[] $breadcrumb
   *   An array of strings representing the current position.
   */
  protected function outputDataBreadcrumb($label, $breadcrumb) {
    $breadcrumb_string = implode(' » ', $breadcrumb);
    $this->io()->writeln("<fg=cyan>$label: $breadcrumb_string</>" . "\n");
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

      // If the property has extra options, add then to the autocompleter
      // values.
      if (isset($property_info['options_extra'])) {
        // Only use the keys from the extra options array, as the values are
        // only meant to be labels.
        $extra_options = array_keys($property_info['options_extra']);

        // The prompt will show an explanation that further options may be used.
        $autocomplete_options = $extra_options;
      }
      else {
        // Only the values are available for autocompletion, not the labels.
        $autocomplete_options = array_keys($options);
      }

      if ($property_info['format'] == 'array') {
        if (!is_array($default)) {
          $default = [$default];
        }

        // Multi-valued property.
        // TODO: consider adding the explanation message on its own line first --
        // but need to work out how to format it, in the face of nonexistent
        // documentation in Symfony code.
        $value = [];

        $question = new \Symfony\Component\Console\Question\ChoiceQuestion(
          $this->getQuestionPromptForProperty("Enter the @label, one per line, empty line to finish", $property_info),
          $options,
          // Feed the default values one by one.
          array_shift($default)
        );
        $question->setAutocompleterValues($autocomplete_options);
        // Hack to work around the question not allowing an empty answer.
        // See https://github.com/drush-ops/drush/issues/2931
        $question->setValidator(function ($answer) use ($autocomplete_options) {
          // Allow an empty answer.
          if (empty($answer)) {
            return $answer;
          }

          // Keep the normal Console error message for an invalid option.
          if (!in_array(strtolower($answer), array_map('strtolower', $autocomplete_options))) {
            throw new \Exception("Value \"{$answer}\" is invalid.");
          }

          return $answer;
        });

        // TODO: bug in Symfony, autocomplete only works on the first value in
        // a multiselect question. To work around, ask a series of questions,
        // allowing the user to end the process with an empty response.
        do {
          $single_value = $this->io()->askQuestion($question);

          if (!empty($single_value)) {
            $value[] = $single_value;
          }

          // For subsequent iterations, the question should not show options.
          $question = new \Symfony\Component\Console\Question\Question(
            $this->getQuestionPromptForProperty("Enter further @label, one per line, empty line to finish", $property_info),
            array_shift($default)
          );
          $question->setAutocompleterValues($autocomplete_options);
          // Hack to work around the question not allowing an empty answer.
          // See https://github.com/drush-ops/drush/issues/2931
          $question->setValidator(function ($answer) use ($autocomplete_options) {
            // Allow an empty answer.
            if (empty($answer)) {
              return $answer;
            }

            // Keep the normal Console error message for an invalid option.
            if (!in_array(strtolower($answer), array_map('strtolower', $autocomplete_options))) {
              throw new \Exception("Value \"{$answer}\" is invalid.");
            }

            return $answer;
          });
        }
        while (!empty($single_value));
      }
      else {
        // Single-valued property.
        // Non-required properties need a 'none' option, as Symfony won't
        // accept an empty value.
        if (!$property_info['required']) {
          $options = array_merge(['none' => 'None'], $options) ;

          $default = 'none';
        }

        $question = new \Symfony\Component\Console\Question\ChoiceQuestion(
          $this->getQuestionPromptForProperty("Enter the @label", $property_info),
          $options,
          $default
        );
        $question->setAutocompleterValues($autocomplete_options);

        // Note that this bypasses DrushStyle::choice()'s override, which
        // converts the options to be keyed numerically, thus hiding the machine
        // name. Good or bad thing for us?
        $value = $this->io()->askQuestion($question);

        // Get rid of the 'none' value if that was the default.
        if ($value === 'none') {
          $value = '';
        }
      }
    }
    elseif ($property_info['format'] == 'array') {
      // Array without options to choose from.
      // TODO: consider adding the explanation message on its own line first --
      // but need to work out how to format it, in the face of nonexistent
      // documentation in Symfony code.
      do {
        $question = new \Symfony\Component\Console\Question\Question(
          $this->getQuestionPromptForProperty("Enter the @label, one per line, empty line to finish", $property_info),
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
        $this->getQuestionPromptForProperty("Do you want a @label", $property_info),
        $default
      );
      // Note that booleans are always required: you have to answer either TRUE
      // or FALSE.

      $value = $this->io()->askQuestion($question);
    }
    elseif ($property_info['format'] == 'string') {
      // String property.
      $question = new \Symfony\Component\Console\Question\Question(
        $this->getQuestionPromptForProperty("Enter the @label", $property_info),
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
   * Gets the prompt string for a question.
   *
   * Helper for askQuestionForProperty().
   *
   * TODO Refactor this into a custom QuestionHelper class.
   *
   * @param string $text
   *   The text for the question, which should contain a '@label' placeholder.
   * @param $property_info
   *   The property's info array.
   *
   * @return string
   *   The text with the label inserted for the placeholder, and the
   *   description, if any, appended.
   */
  protected function getQuestionPromptForProperty($text, $property_info) {
    $prompt = str_replace('@label', $property_info['label'], $text);
    if (isset($property_info['description'])) {
      $prompt .= "\n";
      // Needs a single character indent, apparently.
      $prompt .= ' <comment>(' . $property_info['description']  . ')</comment>';
    }
    if (isset($property_info['options_extra'])) {
      // TODO: Should go after the options list, rather than before, but not
      // possible without custom question class probably.
      $prompt .= "\n";
      // Needs a single character indent, apparently.
      $prompt .= ' <comment>(Additional options available in autocompletion.)</comment>';
    }
    return $prompt;
  }

  /**
   * @hook validate cb:module
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
    return array_keys($this->getModules());
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
    $extensions = $this->getModules();
    return isset($extensions[$module_name]);
  }

  /**
   * Returns a list of all modules in the current site, enabled or not.
   *
   * TODO: broken!
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
    if (!$output->isQuiet()) {
      foreach ($files as $filename => $code) {
        $this->io()->writeln("<fg=green>Proposed $filename:</>" . "\n");

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
        if ($result && !$output->isQuiet()) {
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
    $drupal_root = Drush::bootstrapManager()->getRoot();

    // First try: if the component exists, we write there: nice and simple.
    $component_path = $this->getExistingExtensionPath('module', $component_name);

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
        $module_dir = $this->getConfig()->get('env.cwd') . '/';
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
   * Returns the path to the module if it has previously been written.
   *
   * @return
   *  A Drupal-relative path to the module folder, or NULL if the module
   *  does not already exist.
   */
  protected function getExistingExtensionPath(string $component_type, string $extension_name): ?string {
    $registered_in_drupal = \Drupal::service('extension.list.' . $component_type)->exists($extension_name);
    if ($registered_in_drupal) {
      $extension = \Drupal::service('extension.list.' . $component_type)->get($extension_name);

      // The user may have deleted the module entirely, and in this situation
      // Drupal's extension system would still have told us it exists.
      $really_exists = file_exists($extension->getPath());
      if ($really_exists) {
        return $extension->getPath();
      }
    }

    return NULL;
  }

  /**
   * Update analysis data on Drupal components.
   *
   * @command cb:update
   *
   * @usage drush cb:update
   *   Update data on Drupal components, storing in the default location.
   * @usage drush cb:update --data-location=relative/path
   *   Update data on hooks, storing data in public://relative/path.
   * @usage drush cb:update --data-location=/absolute/path
   *   Update data on hooks, storing data in /absolute/path.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @aliases cbu
   * @code_builder
   */
  public function commandUpdateDefinitions(OutputInterface $output) {
    // Get our task handler. This performs a sanity check which throws an
    // exception.
    $task_handler_collect = $this->getCodeBuilderTask('Collect');

    $job_list = $task_handler_collect->getJobList();

    $results = [];
    $this->io()->progressStart(count($job_list));
    foreach ($job_list as $job) {
      $task_handler_collect->collectComponentDataIncremental([$job], $results);
      $this->io()->progressAdvance(1);
    }
    $this->io()->progressFinish();

    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();

    $output->writeln("Drupal Code Builder's analysis has detected the following in your Drupal codebase:");

    $table = new Table($output);
    $table->setHeaders(array('Type', 'Count'));
    foreach ($results as $label => $count) {
      $rows[] = [ucfirst($label), $count];
    }
    $table->setRows($rows);
    $table->render();

    $output->writeln("Data has been processed and written to {$hooks_directory}.");

    return TRUE;
  }

  /**
   * List stored analysis data on Drupal components.
   *
   * @command cb:list
   *
   * @option type Which type of data to list. The valid options are defined
   * by DrupalCodeBuilder, and include:
   *   'all': show everything.
   *   'hooks': show hooks.
   *   'plugins': show plugin types.
   *   'services': show services.
   *   'tags': show tagged service types.
   *   'fields': show field types.
   * @usage drush cb:list
   *   List stored analysis data on Drupal components.
   * @usage drush cb:list --type=plugins
   *   List stored analysis data on Drupal plugin types.
   * @bootstrap DRUSH_BOOTSTRAP_DRUPAL_FULL
   * @aliases cbl
   * @code_builder
   */
  public function commandListDefinitions(OutputInterface $output, $options = ['type' => 'all']) {
    // TODO: add a --format option, same as 'drush list'.
    // TODO: add a --filter option, same as 'drush list'.
    // TODO: restore listing hook presets.

    // Callback for array_walk() to concatenate the array key and value.
    $list_walker = function (&$value, $key) {
      $value = "{$key}: $value";
    };

    $task_report = $this->getCodeBuilderTask('ReportSummary');

    $data = $task_report->listStoredData();

    if ($options['type'] != 'all') {
      if (!isset($data[$options['type']])) {
        throw new \Exception("Invalid type '{$options['type']}'.");
      }

      $data = array_intersect_key($data, [$options['type'] => TRUE]);
    }

    foreach ($data as $type_data) {
      $this->io()->title($type_data['label'] . ':');

      if (is_array(reset($type_data['list']))) {
        // Grouped list.
        foreach ($type_data['list'] as $group_title => $group_list) {
          $this->io()->section($group_title);

          array_walk($group_list, $list_walker);
          $this->io()->listing($group_list);
        }
      }
      else {
        array_walk($type_data['list'], function (&$value, $key) {
          $value = "{$key}: $value";
        });
        $this->io()->listing($type_data['list']);
      }
    }

    // Show a table summarizing counts.
    if ($options['type'] == 'all') {
      $table = new \Symfony\Component\Console\Helper\Table($output);
      $table->setHeaders(array('Type', 'Count'));
      foreach ($data as $type_data) {
        $rows[] = [$type_data['label'], $type_data['count']];
      }
      $table->setRows($rows);
      $table->render();
    }

    $time = $task_report->lastUpdatedDate();
    $hooks_directory = \DrupalCodeBuilder\Factory::getEnvironment()->getHooksDirectory();
    $output->writeln(strtr("Component data retrieved from @dir.", array('@dir' => $hooks_directory)));
    $output->writeln(strtr("Component data was processed on @time.", array(
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
        $message = "No component data was found. Run 'drush cb:update' to process component data from your site's code files.";
        break;
    }
    throw new \Exception($message);
  }

  /**
   * Gets a message to show for different levels of DCB sanity failure.
   *
   * @param \DrupalCodeBuilder\Exception\SanityException $e
   *   The sanity exception.
   *
   * @return
   *   The message string.
   */
  protected function getSanityLevelMessage(\DrupalCodeBuilder\Exception\SanityException $e): string {
    return match ($e->getFailedSanityLevel()) {
      'data_directory_exists' => "The component data directory could not be created or is not writable.",
      'component_data_processed' => "No component data was found. Run 'drush cb:update' to process component data from your site's code files.",
    };
  }

}
