<?php

namespace Tests\Feature;

use App\Support\ImageNormalizer;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImageNormalizerTest extends TestCase
{
    private ImageNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new ImageNormalizer;
    }

    private function assertRejected(UploadedFile $file): void
    {
        try {
            $this->normalizer->normalize($file);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('photo', $exception->errors());

            return;
        }

        $this->fail('The normalizer should have rejected this file.');
    }

    public function test_a_jpeg_becomes_a_webp_of_the_same_size(): void
    {
        $result = $this->normalizer->normalize(UploadedFile::fake()->image('photo.jpg', 800, 600));

        $this->assertSame('RIFF', substr($result['data'], 0, 4));
        $this->assertSame('WEBP', substr($result['data'], 8, 4));
        $this->assertSame(800, $result['width']);
        $this->assertSame(600, $result['height']);

        $info = getimagesizefromstring($result['data']);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertInstanceOf(GdImage::class, imagecreatefromstring($result['data']));
    }

    public function test_a_png_becomes_a_webp(): void
    {
        $result = $this->normalizer->normalize(UploadedFile::fake()->image('photo.png', 400, 300));

        $this->assertSame('WEBP', substr($result['data'], 8, 4));
        $this->assertSame(400, $result['width']);
        $this->assertSame(300, $result['height']);
    }

    public function test_the_type_comes_from_the_content_not_from_the_extension(): void
    {
        $jpeg = UploadedFile::fake()->image('source.jpg', 500, 400);
        $content = (string) file_get_contents((string) $jpeg->getRealPath());

        $result = $this->normalizer->normalize(UploadedFile::fake()->createWithContent('renamed.png', $content));

        $this->assertSame('WEBP', substr($result['data'], 8, 4));
        $this->assertSame(500, $result['width']);
    }

    public function test_a_landscape_picture_is_shrunk_to_the_maximum_side(): void
    {
        $result = $this->normalizer->normalize(UploadedFile::fake()->image('big.jpg', 3000, 2000));

        $this->assertSame(ImageNormalizer::MAX_SIDE, $result['width']);
        $this->assertEqualsWithDelta(1067, $result['height'], 1);
    }

    public function test_a_portrait_picture_is_shrunk_to_the_maximum_side(): void
    {
        $result = $this->normalizer->normalize(UploadedFile::fake()->image('tall.jpg', 1000, 2500));

        $this->assertSame(ImageNormalizer::MAX_SIDE, $result['height']);
        $this->assertEqualsWithDelta(640, $result['width'], 1);
    }

    public function test_a_picture_below_the_maximum_side_is_not_resized(): void
    {
        $result = $this->normalizer->normalize(UploadedFile::fake()->image('ok.jpg', 1600, 900));

        $this->assertSame(1600, $result['width']);
        $this->assertSame(900, $result['height']);
    }

    public function test_files_that_are_not_images_are_rejected(): void
    {
        $this->assertRejected(UploadedFile::fake()->createWithContent('evil.jpg', "<?php echo 'x'; ?>"));
        $this->assertRejected(UploadedFile::fake()->createWithContent('vector.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'));
        $this->assertRejected(UploadedFile::fake()->createWithContent('doc.jpg', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n"));
        $this->assertRejected(UploadedFile::fake()->createWithContent('empty.jpg', ''));
    }

    public function test_other_image_formats_are_rejected(): void
    {
        $this->assertRejected(UploadedFile::fake()->image('animation.gif', 300, 300));
    }

    public function test_an_image_that_declares_too_many_pixels_is_rejected_before_decoding(): void
    {
        $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 6000, 6000)."\x08\x02\x00\x00\x00".pack('N', 0);

        $this->assertRejected(UploadedFile::fake()->createWithContent('bomb.png', $header));
    }
}
