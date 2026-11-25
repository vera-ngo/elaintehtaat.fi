<?php

declare(strict_types=1);

namespace Drupal\elaintehtaat\Hook;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\elaintehtaat\Album\OneAlbumPerMedia;
use Drupal\elaintehtaat\Entity\Album;
use Drupal\elaintehtaat\Plugin\Validation\Constraint\OneAlbumPerMediaConstraint;
use Drupal\media_library\MediaLibraryState;
use Drupal\node\NodeInterface;
use Drupal\views\Plugin\views\query\QueryPluginBase;
use Drupal\views\Plugin\views\query\Sql;
use Drupal\views\ViewExecutable;

/**
 * Keeps every media item in at most one album.
 *
 * - Forms and APIs that validate get a violation on the album media field.
 * - Code that saves an album without validating gets an exception.
 * - The media library opened from an album hides media other albums hold.
 * - The status report lists media still in several albums from before.
 *
 * @see \Drupal\elaintehtaat\Album\OneAlbumPerMedia
 */
final class OneAlbumPerMediaHooks {

  use StringTranslationTrait;

  /**
   * The opener the media library widget uses.
   */
  private const string WIDGET_OPENER = 'media_library.opener.field_widget';

  /**
   * How many media items the status report names.
   */
  private const int REPORT_LIMIT = 20;

  public function __construct(
    private readonly OneAlbumPerMedia $rule,
    private readonly Connection $database,
  ) {}

  /**
   * Implements hook_entity_bundle_field_info_alter().
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The fields of the bundle.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    if ($entity_type->id() !== 'node' || $bundle !== Album::BUNDLE) {
      return;
    }
    $field = $fields[OneAlbumPerMedia::FIELD] ?? NULL;
    if ($field instanceof FieldConfigInterface) {
      $field->addConstraint(OneAlbumPerMediaConstraint::PLUGIN_ID);
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_presave() for node entities.
   */
  #[Hook('node_presave')]
  public function nodePresave(NodeInterface $node): void {
    if ($node->bundle() !== Album::BUNDLE) {
      return;
    }
    $violations = $this->rule->violations($node, $node->getOriginal());
    if ($violations === []) {
      return;
    }

    $parts = [];
    foreach ($violations as $media_id => $album_id) {
      $parts[] = $album_id === NULL
        ? sprintf('media %s is in the album more than once', $media_id)
        : sprintf('media %s is already in album %s', $media_id, $album_id);
    }
    throw new \LogicException(sprintf(
      'A media item belongs to at most one album. Album %s not saved: %s.',
      $node->isNew() ? '(new)' : $node->id(),
      implode('; ', $parts),
    ));
  }

  /**
   * Implements hook_views_query_alter().
   *
   * Hides media held by other albums from the media library when it is opened
   * from an album's media field. Media already in the album being edited stays
   * visible.
   */
  #[Hook('views_query_alter')]
  public function viewsQueryAlter(ViewExecutable $view, QueryPluginBase $query): void {
    if ($view->id() !== 'media_library' || !str_starts_with((string) $view->current_display, 'widget') || !$query instanceof Sql) {
      return;
    }
    try {
      $state = MediaLibraryState::fromRequest($view->getRequest());
    }
    catch (\InvalidArgumentException) {
      // A preview in the Views UI has no media library state.
      return;
    }
    $parameters = $state->getOpenerParameters();
    if (
      $state->getOpenerId() !== self::WIDGET_OPENER
      || ($parameters['entity_type_id'] ?? NULL) !== 'node'
      || ($parameters['bundle'] ?? NULL) !== Album::BUNDLE
      || ($parameters['field_name'] ?? NULL) !== OneAlbumPerMedia::FIELD
    ) {
      return;
    }

    $table = $this->rule->table();
    if ($table === NULL) {
      return;
    }
    [$table, $column] = $table;
    $held = $this->database->select($table, 'held')
      ->fields('held', [$column])
      ->condition('held.bundle', Album::BUNDLE)
      ->condition('held.deleted', 0);
    if (!empty($parameters['entity_id'])) {
      $held->condition('held.entity_id', $parameters['entity_id'], '<>');
    }
    // The condition takes a subquery as its value, which the Views query
    // passes on to the database layer untouched.
    // @phpstan-ignore argument.type
    $query->addWhere('', $view->storage->get('base_table') . '.mid', $held, 'NOT IN');
  }

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $media = $this->rule->mediaInSeveralAlbums();
    if ($media === []) {
      return [
        'elaintehtaat_one_album_per_media' => [
          'title' => $this->t('Media in several albums'),
          'value' => $this->t('None'),
          'severity' => RequirementSeverity::OK,
        ],
      ];
    }

    $items = [];
    foreach (array_slice($media, 0, self::REPORT_LIMIT, TRUE) as $media_id => $album_ids) {
      $links = array_map(
        static fn (string $id): string => Url::fromRoute('entity.node.edit_form', ['node' => $id])->toString(),
        $album_ids,
      );
      $items[] = $this->t('Media @media: @albums', [
        '@media' => $media_id,
        '@albums' => implode(', ', $links),
      ]);
    }

    return [
      'elaintehtaat_one_album_per_media' => [
        'title' => $this->t('Media in several albums'),
        'value' => $this->formatPlural(count($media), '1 media item', '@count media items'),
        'description' => [
          'intro' => [
            '#markup' => $this->t('A media item belongs to at most one album. Remove each of these from all albums but one, or duplicate the media if both albums need it.'),
          ],
          'list' => [
            '#theme' => 'item_list',
            '#items' => $items,
          ],
        ],
        'severity' => RequirementSeverity::Warning,
      ],
    ];
  }

}
