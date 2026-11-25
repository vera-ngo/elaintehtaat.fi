<?php

declare(strict_types=1);

namespace Drupal\elaintehtaat\Album;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\elaintehtaat\Entity\Album;
use Drupal\elaintehtaat\Entity\Project;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Finds the albums a media item or a project belongs to.
 *
 * Albums reference their media and their project, not the other way round, so
 * the only way from a media item or a project back to its albums is a reverse
 * query. A media item belongs to at most one album.
 *
 * @see \Drupal\elaintehtaat\Hook\MediaTileHooks
 * @see \Drupal\elaintehtaat\Hook\ProjectSummaryHooks
 * @see \Drupal\elaintehtaat\Plugin\search_api\processor\AlbumData
 */
final readonly class AlbumLookup {

  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * The album holding a media item, if there is one.
   *
   * A media item belongs to at most one album. Media that is still in several
   * albums from before that rule gets the newest one, so the answer is stable
   * until the duplicates are resolved.
   *
   * The album is returned whatever its status: callers that show it decide
   * whether an unpublished album counts.
   *
   * @param \Drupal\media\MediaInterface $media
   *   The media item to find the album of.
   * @param string|null $langcode
   *   The language to read the album in, or NULL for its default language.
   *   An album without that translation is returned in its default language.
   *
   * @see \Drupal\elaintehtaat\Album\OneAlbumPerMedia
   */
  public function albumOf(MediaInterface $media, ?string $langcode = NULL): ?Album {
    if ($media->isNew()) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', Album::BUNDLE)
      ->condition('field_album_media.target_id', $media->id())
      ->sort('created', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      return NULL;
    }

    $album = $storage->load(reset($ids));
    if (!$album instanceof Album) {
      return NULL;
    }
    if ($langcode !== NULL && $album->hasTranslation($langcode)) {
      $album = $album->getTranslation($langcode);
    }

    return $album;
  }

  /**
   * The published albums of a project, newest first.
   *
   * @return list<\Drupal\elaintehtaat\Entity\Album>
   *   The albums, newest album date first.
   */
  public function albumsOfProject(Project $project): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', Album::BUNDLE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_project.target_id', $project->id())
      ->sort('field_date', 'DESC')
      ->sort('created', 'DESC')
      ->execute();
    if (!$ids) {
      return [];
    }

    // loadMultiple() ignores the order the IDs come in, so restore it.
    $albums = $storage->loadMultiple($ids);
    $ordered = [];
    foreach ($ids as $id) {
      if (($albums[$id] ?? NULL) instanceof Album) {
        $ordered[] = $albums[$id];
      }
    }

    return $ordered;
  }

  /**
   * The IDs of the media items an album holds, in album order.
   *
   * Reads the field values rather than the referenced entities, so it also
   * works on the original of an album being saved.
   *
   * @return list<string>
   *   The media IDs.
   */
  public function mediaIdsOf(NodeInterface $album): array {
    if ($album->bundle() !== Album::BUNDLE || !$album->hasField('field_album_media')) {
      return [];
    }

    return array_map(
      static fn (mixed $id): string => (string) $id,
      array_column($album->get('field_album_media')->getValue(), 'target_id'),
    );
  }

}
