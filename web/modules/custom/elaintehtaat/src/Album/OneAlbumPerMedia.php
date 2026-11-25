<?php

declare(strict_types=1);

namespace Drupal\elaintehtaat\Album;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\elaintehtaat\Entity\Album;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\node\NodeInterface;

/**
 * The rule that a media item belongs to at most one album.
 *
 * Only what an album save adds is checked: media it takes on that another
 * album already holds, and media it holds more times than before. Media that
 * was in several albums before the rule existed does not block editing either
 * album; the status report lists it until it is resolved by hand.
 *
 * @see \Drupal\elaintehtaat\Plugin\Validation\Constraint\OneAlbumPerMediaConstraint
 * @see \Drupal\elaintehtaat\Hook\OneAlbumPerMediaHooks
 */
final readonly class OneAlbumPerMedia {

  /**
   * The album field that holds the media.
   */
  public const string FIELD = 'field_album_media';

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private Connection $database,
    private AlbumLookup $albumLookup,
  ) {}

  /**
   * The media an album save would add against the rule.
   *
   * @param \Drupal\node\NodeInterface $album
   *   The album as it is about to be saved.
   * @param \Drupal\node\NodeInterface|null $original
   *   The album as it is stored, or NULL for a new album.
   *
   * @return array<string, string|null>
   *   Keyed by media ID: the ID of the other album that already holds it, or
   *   NULL when the album itself holds it more than once.
   */
  public function violations(NodeInterface $album, ?NodeInterface $original): array {
    $counts = array_count_values($this->albumLookup->mediaIdsOf($album));
    if ($counts === []) {
      return [];
    }
    $before = $original === NULL ? [] : array_count_values($this->albumLookup->mediaIdsOf($original));

    $violations = [];
    foreach ($counts as $id => $count) {
      if ($count > 1 && $count > ($before[$id] ?? 0)) {
        $violations[(string) $id] = NULL;
      }
    }

    $added = array_map('strval', array_keys(array_diff_key($counts, $before)));
    $table = $this->table();
    if ($added === [] || $table === NULL) {
      return $violations;
    }
    [$table, $column] = $table;
    $query = $this->database->select($table, 'm')
      ->fields('m', ['entity_id', $column])
      ->condition('m.bundle', Album::BUNDLE)
      ->condition('m.deleted', 0)
      ->condition("m.$column", $added, 'IN')
      ->orderBy('m.entity_id');
    if (!$album->isNew()) {
      $query->condition('m.entity_id', $album->id(), '<>');
    }
    foreach ($query->execute() ?? [] as $row) {
      $violations[(string) $row->{$column}] ??= (string) $row->entity_id;
    }

    return $violations;
  }

  /**
   * Media that is in more than one album, as left over from before the rule.
   *
   * @return array<string, list<string>>
   *   Album IDs in ascending order, keyed by media ID.
   */
  public function mediaInSeveralAlbums(): array {
    $table = $this->table();
    if ($table === NULL) {
      return [];
    }
    [$table, $column] = $table;
    $several = $this->database->select($table, 'd')
      ->fields('d', [$column])
      ->condition('d.bundle', Album::BUNDLE)
      ->condition('d.deleted', 0)
      ->groupBy("d.$column");
    $several->having('COUNT(DISTINCT d.entity_id) > 1');

    $rows = $this->database->select($table, 'm')
      ->fields('m', [$column, 'entity_id'])
      ->condition('m.bundle', Album::BUNDLE)
      ->condition('m.deleted', 0)
      ->condition("m.$column", $several, 'IN')
      ->distinct()
      ->orderBy("m.$column")
      ->orderBy('m.entity_id')
      ->execute() ?? [];

    $media = [];
    foreach ($rows as $row) {
      $media[(string) $row->{$column}][] = (string) $row->entity_id;
    }

    return $media;
  }

  /**
   * The data table of the album media field and its target ID column.
   *
   * @return array{string, string}|null
   *   The table name and the column name, or NULL while the field does not
   *   exist, as during a site install.
   */
  public function table(): ?array {
    $storage = $this->entityTypeManager->getStorage('node');
    // @phpstan-ignore instanceof.alwaysTrue
    $mapping = $storage instanceof SqlEntityStorageInterface ? $storage->getTableMapping() : NULL;
    if (!$mapping instanceof DefaultTableMapping) {
      return NULL;
    }
    $definition = $this->entityTypeManager->getStorage('field_storage_config')->load('node.' . self::FIELD);
    if (!$definition instanceof FieldStorageConfigInterface) {
      return NULL;
    }

    return [
      $mapping->getDedicatedDataTableName($definition),
      $mapping->getFieldColumnName($definition, 'target_id'),
    ];
  }

}
