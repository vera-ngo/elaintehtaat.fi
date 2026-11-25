<?php

declare(strict_types=1);

namespace Drupal\Tests\elaintehtaat\Kernel;

use Drupal\elaintehtaat\Album\AlbumLookup;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\elaintehtaat\Traits\ContentModelTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding the album a media item belongs to.
 */
#[Group('elaintehtaat')]
#[RunTestsInSeparateProcesses]
class AlbumLookupTest extends KernelTestBase {

  use ContentModelTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::CONTENT_MODEL_MODULES, 'language', 'content_translation'];

  /**
   * The service under test.
   */
  private AlbumLookup $lookup;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installContentModel();
    $this->installConfig(['language']);
    // Saving a node translation rebuilds the node access records.
    $this->installSchema('node', ['node_access']);
    $this->lookup = $this->container->get(AlbumLookup::class);
  }

  /**
   * The album holding the item is found, whatever its status.
   */
  public function testAlbumOf(): void {
    $media = $this->createImageMedia();
    $draft = $this->createImageMedia('Draft frame');
    $album = $this->createAlbum(['title' => 'Sighting', 'field_album_media' => [$media]]);
    $unpublished = $this->createAlbum([
      'title' => 'Draft',
      'status' => 0,
      'field_album_media' => [$draft],
    ]);
    $this->createAlbum(['title' => 'Elsewhere', 'field_album_media' => [$this->createImageMedia('Other')]]);

    $this->assertSame($album->id(), $this->lookup->albumOf($media)?->id());
    $this->assertSame($unpublished->id(), $this->lookup->albumOf($draft)?->id());
  }

  /**
   * An item in no album at all yields nothing.
   */
  public function testAlbumOfWithoutAnyAlbum(): void {
    $this->assertNull($this->lookup->albumOf($this->createImageMedia()));
  }

  /**
   * The album is read in the language asked for, when it has that language.
   */
  public function testAlbumOfReadsTheTranslation(): void {
    ConfigurableLanguage::createFromLangcode('fi')->save();
    ConfigurableLanguage::createFromLangcode('sv')->save();

    $media = $this->createImageMedia();
    $album = $this->createAlbum(['title' => 'Pig farm', 'field_album_media' => [$media]]);
    $album->addTranslation('fi', ['title' => 'Sikala'])->save();

    $this->assertSame('Pig farm', $this->lookup->albumOf($media)?->label());
    $this->assertSame('Sikala', $this->lookup->albumOf($media, 'fi')?->label());
    $this->assertSame('Pig farm', $this->lookup->albumOf($media, 'sv')?->label());
  }

  /**
   * Media left in several albums from before the rule gets the newest one.
   */
  public function testAlbumOfWithLeftoverDuplicates(): void {
    $media = $this->createImageMedia();
    $this->createAlbum(['title' => 'Older', 'created' => 100, 'field_album_media' => [$media]]);
    $newer = $this->createAlbum([
      'title' => 'Newer',
      'created' => 200,
      'field_album_media' => [$this->createImageMedia('Other')],
    ]);
    $this->addToAlbumDirectly($newer, $media);

    $this->assertSame($newer->id(), $this->lookup->albumOf($media)?->id());
  }

  /**
   * The media IDs of an album are read off the field, in album order.
   */
  public function testMediaIdsOf(): void {
    $first = $this->createImageMedia('First');
    $second = $this->createImageMedia('Second');
    $album = $this->createAlbum(['field_album_media' => [$first, $second]]);

    $this->assertSame(
      [(string) $first->id(), (string) $second->id()],
      $this->lookup->mediaIdsOf($album),
    );
    // A node that is not an album holds no media, whatever it references.
    $this->assertSame([], $this->lookup->mediaIdsOf($this->createProject()));
  }

}
