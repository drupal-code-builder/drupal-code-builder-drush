This is a set of Drush commands (for Drush 9.x) for generating code with the
Drupal Code Builder library
(https://github.com/drupal-code-builder/drupal-code-builder).

## Installation

1. Place this folder somewhere where Drush will locate it as a command. (See
  http://docs.drush.org/en/master/commands/#create-commandfiledrushinc for
  possible locations.)
2. Rename the folder to 'CodeBuilder'. (TODO: remove the need for this step!)
3. Do `composer require drupal-code-builder/drupal-code-builder` in the Drush
  installation folder.
4. Do `drush cc drush` to rebuild Drush's cache of commands.
5. Do `drush mb-download` in your Drupal site. This detects hooks, services, and
  plugin types in your Drupal site's codebase and analyses them for use with
  Drupal Code Builder.

## Usage

The following commands are available:

- `drush cb-list`: Lists all the hooks, services, and plugins that Drupal Code
  Builder has detected in your Drupal site's codebase.
- `drush cb-update`: Updates the stored definitions of Drupal hooks, services
  and plugin types.
- TODO: document remaining commands.

## A note on history

Commits in this repository older than 2017-08-22 are extracted from other
repositories that originally were the home of this command file: the drupal.org
module_builder project, and a drush fork.
They were extracted using git filter-branch, and reconstituted into a single
history with git graft and git filter-branch.
