<?php

namespace Drupal\Tests\swiftmailer\Kernel\Plugin\Mail;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Render\Markup;
use Drupal\KernelTests\KernelTestBase;

/**
 * @coversDefaultClass \Drupal\swiftmailer\Plugin\Mail\SwiftMailer
 * @group swiftmailer
 */
class FormatTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'user',
    'filter',
    'swiftmailer',
    'system',
  ];

  /**
   * The swiftmailer plugin.
   *
   * @var \Drupal\swiftmailer\Plugin\Mail\SwiftMailer
   */
  protected $plugin;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->installConfig([
      'swiftmailer',
      'filter',
    ]);
    $this->installEntitySchema('user');
    $this->installSchema('user', 'users_data');
    $this->plugin = $this->container->get('plugin.manager.mail')
      ->createInstance('swiftmailer');

    // Install the test theme for a simple template.
    \Drupal::service('theme_installer')->install(['swiftmailer_test_theme']);
    \Drupal::configFactory()
      ->getEditable('system.theme')
      ->set('default', 'swiftmailer_test_theme')
      ->save();
  }

  /**
   * Tests formatting the message.
   *
   * @dataProvider bodyDataProvider
   */
  public function testFormat(array $message, $expected, $expected_plain) {
    $message['module'] = 'swiftmailer';
    $message['key'] = 'FormatTest';
    $message['subject'] = 'FormatTest';

    $message['params']['format'] = SWIFTMAILER_FORMAT_HTML;
    $actual = $this->plugin->format($message);
    $expected = implode(PHP_EOL, $expected) . PHP_EOL;
    $this->assertSame($expected, (string) $actual['body']);

    $message['params']['format'] = SWIFTMAILER_FORMAT_PLAIN;
    $actual = $this->plugin->format($message);
    $expected_plain = implode(PHP_EOL, $expected_plain);
    $this->assertSame($expected_plain, (string) $actual['body']);
  }

  /**
   * Data provider of body data.
   */
  public function bodyDataProvider() {
    return [
      'with html' => [
        'message' => [
          'body' => [
            Markup::create('<p>Lorem ipsum &amp; dolor sit amet</p>'),
            Markup::create('<p>consetetur &lt; sadipscing elitr</p>'),
          ],
        ],
        'expected' => [
          "<p>Lorem ipsum &amp; dolor sit amet</p>",
          "<p>consetetur &lt; sadipscing elitr</p>",
        ],
        'expected_plain' => [
          "Lorem ipsum & dolor sit amet\n\n",
          "consetetur < sadipscing elitr\n\n",
        ],
      ],

      'no html' => [
        'message' => [
          'body' => [
            "Lorem ipsum & dolor sit amet\nconsetetur < sadipscing elitr",
          ],
        ],
        'expected' => ["<p>Lorem ipsum &amp; dolor sit amet<br />\nconsetetur &lt; sadipscing elitr</p>\n"],
        'expected_plain' => ["Lorem ipsum & dolor sit amet\nconsetetur < sadipscing elitr"],
      ],

      'mixed' => [
        'message' => [
          'body' => [
            'Hello & World',
            // Next, the content of the message contains strings that look like
            // markup.  For example it could be a website lecturer explaining
            // to students about the <strong> tag.
            'Hello & <strong>World</strong>',
            new FormattableMarkup('Hello &amp; World #@number', ['@number' => 2]),
            Markup::create('Hello &amp; <strong>World</strong>'),
          ],
        ],
        'expected' => [
          "<p>Hello &amp; World</p>\n",
          "<p>Hello &amp; &lt;strong&gt;World&lt;/strong&gt;</p>\n",
          "Hello &amp; World #2",
          "Hello &amp; <strong>World</strong>",
        ],
        'expected_plain' => [
          "Hello & World",
          "Hello & <strong>World</strong>",
          "Hello & World #2\n",
          "Hello & *World*\n",
        ],
      ],
    ];
  }

}
