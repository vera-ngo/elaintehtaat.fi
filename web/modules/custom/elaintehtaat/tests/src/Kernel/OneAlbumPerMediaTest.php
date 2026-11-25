<?php

declare(strict_types=1);

namespace Drupal\Tests\elaintehtaat\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\elaintehtaat\Album\AlbumLookup;
use Drupal\elaintehtaat\Album\OneAlbumPerMedia;
use Drupal\elaintehtaat\Entity\Album;
use Drupal\elaintehtaat\Entity\Image;
use Drupal\media_library\MediaLibraryState;
use Drupal\Tests\elaintehtaat\Traits\ContentModelTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that a media item belongs to at most one album.
 *
 * @see \Drupal\elaintehtaat\Album\OneAlbumPerMedia
 * @see \Drupal\elaintehtaat\Hook\OneAlbumPerMediaHooks
 */
#[Group('elaintehtaat')]
#[RunTestsInSeparateProcesses]
class OneAlbumPerMediaTest extends KernelTestBase {

  use ContentModelTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::CONTENT_MODEL_MODULES, 'views', 'media_library'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installContentModel();
    // Saving an existing node rebuilds its access records.
    $this->installSchema('node', ['node_access']);
  }

  /**
   * Media another album holds is rejected, naming that album.
   */
  public function testMediaOfAnotherAlbumFailsValidation(): void {
    $media = $this->createImageMedia('Sow in a crate');
    $this->createAlbum(['title' => 'Pig farm', 'field_album_media' => [$media]]);

    $album = $this->newAlbum('Second visit', [$media]);

    $this->assertSame(
      ['Sow in a crate is already in the album Pig farm. Remove it from that album first.'],
      $this->messages($album),
    );
  }

  /**
   * An album holding the same media twice is rejected.
   */
  public function testRepeatedMediaFailsValidation(): void {
    $media = $this->createImageMedia('Sow in a crate');

    $album = $this->newAlbum('Pig farm', [$media, $media]);

    $this->assertSame(['Sow in a crate is in this album more than once.'], $this->messages($album));
  }

  /**
   * Saving without validating does not get around the rule.
   */
  public function testSavingWithoutValidationThrows(): void {
    $media = $this->createImageMedia();
    $this->createAlbum(['field_album_media' => [$media]]);

    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessage('A media item belongs to at most one album.');
    $this->createAlbum(['title' => 'Second visit', 'field_album_media' => [$media]]);
  }

  /**
   * Media can move once it has been taken out of its album.
   */
  public function testMovingMediaBetweenAlbums(): void {
    $media = $this->createImageMedia();
    $keep = $this->createImageMedia('Kept');
    $first = $this->createAlbum(['field_album_media' => [$media, $keep]]);

    $first->set('field_album_media', [$keep])->save();
    $second = $this->newAlbum('Second visit', [$media]);

    $this->assertSame([], $this->messages($second));
    $second->save();
    $this->assertSame($second->id(), $this->container->get(AlbumLookup::class)->albumOf($media)?->id());
  }

  /**
   * Media left in several albums does not block editing either album.
   */
  public function testLeftoverDuplicatesDoNotBlockEditing(): void {
    $media = $this->createImageMedia();
    $first = $this->createAlbum(['title' => 'First', 'field_album_media' => [$media]]);
    $second = $this->createAlbum(['title' => 'Second', 'field_album_media' => [$this->createImageMedia('Other')]]);
    $this->addToAlbumDirectly($second, $media);

    $second = Album::load($second->id());
    $this->assertInstanceOf(Album::class, $second);
    $second->setTitle('Second, renamed');
    $this->assertSame([], $this->messages($second));
    $second->save();

    $this->assertSame(
      [(string) $media->id() => [(string) $first->id(), (string) $second->id()]],
      $this->container->get(OneAlbumPerMedia::class)->mediaInSeveralAlbums(),
    );
  }

  /**
   * The status report warns about media in several albums, and only then.
   */
  public function testStatusReport(): void {
    $media = $this->createImageMedia();
    $this->createAlbum(['field_album_media' => [$media]]);

    $requirement = $this->requirement();
    $this->assertSame(RequirementSeverity::OK, $requirement['severity']);

    $second = $this->createAlbum(['title' => 'Second', 'field_album_media' => [$this->createImageMedia('Other')]]);
    $this->addToAlbumDirectly($second, $media);

    $requirement = $this->requirement();
    $this->assertSame(RequirementSeverity::Warning, $requirement['severity']);
    $this->assertSame('1 media item', (string) $requirement['value']);
  }

  /**
   * The media library opened from an album hides media other albums hold.
   */
  public function testMediaLibraryHidesMediaOfOtherAlbums(): void {
    $this->installConfig(['media_library']);
    $this->setUpCurrentUser(['uid' => 1]);

    $elsewhere = $this->createImageMedia('Elsewhere');
    $own = $this->createImageMedia('Own');
    $free = $this->createImageMedia('Free');
    $this->createAlbum(['title' => 'Other', 'field_album_media' => [$elsewhere]]);
    $album = $this->createAlbum(['title' => 'Edited', 'field_album_media' => [$own]]);

    // Editing an album: its own media and free media are offered.
    $this->assertEqualsCanonicalizing(
      [$own->id(), $free->id()],
      $this->libraryMediaIds(['entity_id' => $album->id()]),
    );
    // Creating an album: only free media is offered.
    $this->assertSame([$free->id()], $this->libraryMediaIds([]));
    // Opened from another field, nothing is hidden.
    $this->assertEqualsCanonicalizing(
      [$elsewhere->id(), $own->id(), $free->id()],
      $this->libraryMediaIds(['field_name' => 'field_other']),
    );
  }

  /**
   * An unsaved album holding the given media.
   *
   * @param string $title
   *   The album title.
   * @param list<\Drupal\media\MediaInterface> $media
   *   The media.
   */
  private function newAlbum(string $title, array $media): Album {
    return Album::create(['type' => Album::BUNDLE, 'title' => $title, 'field_album_media' => $media]);
  }

  /**
   * The violation messages on the album media field, without markup.
   *
   * @return list<string>
   *   The messages.
   */
  private function messages(Album $album): array {
    $messages = [];
    foreach ($album->validate()->getByField(OneAlbumPerMedia::FIELD) as $violation) {
      $messages[] = strip_tags((string) $violation->getMessage());
    }

    return $messages;
  }

  /**
   * The status report entry of the rule.
   */
  private function requirement(): array {
    $requirements = $this->container->get('module_handler')->invoke('elaintehtaat', 'runtime_requirements');

    return $requirements['elaintehtaat_one_album_per_media'];
  }

  /**
   * The IDs of the media the media library widget lists.
   *
   * @param array<string, string|int> $parameters
   *   Opener parameters that differ from an album's media field.
   *
   * @return list<string>
   *   The media IDs.
   */
  private function libraryMediaIds(array $parameters): array {
    $state = MediaLibraryState::create(
      'media_library.opener.field_widget',
      [Image::BUNDLE],
      Image::BUNDLE,
      -1,
      $parameters + [
        'entity_type_id' => 'node',
        'bundle' => Album::BUNDLE,
        'field_name' => OneAlbumPerMedia::FIELD,
      ],
    );
    $request = Request::create('/media-library', 'GET', $state->all());

    $view = Views::getView('media_library');
    $this->assertNotNull($view);
    $view->setRequest($request);
    $view->setDisplay('widget');
    $view->execute();

    $ids = [];
    foreach ($view->result as $row) {
      $ids[] = (string) $row->_entity->id();
    }

    return $ids;
  }

}
