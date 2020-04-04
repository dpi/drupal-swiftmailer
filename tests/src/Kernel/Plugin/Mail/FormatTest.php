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
  public function testFormat(array $message, $expected, $expected_plain = NULL) {
    $message['module'] = 'swiftmailer';
    $message['key'] = 'FormatTest';
    $message['subject'] = 'FormatTest';

    $message['params']['format'] = SWIFTMAILER_FORMAT_HTML;
    $actual = $this->plugin->format($message);
    $expected = implode(PHP_EOL, $expected) . PHP_EOL;
    $this->assertSame($expected, (string) $actual['body']);

    if ($expected_plain) {
      $message['params']['format'] = SWIFTMAILER_FORMAT_PLAIN;
      $actual = $this->plugin->massageMessageBody($message);
      $expected_plain = implode(PHP_EOL, $expected_plain);
      $this->assertSame($expected_plain, (string) $actual['body']);
    }
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
          "<p>Lorem ipsum &amp; dolor sit amet</p>",
          "<p>consetetur &lt; sadipscing elitr</p>",
        ],
      ],
      'no html' => [
        'message' => [
          'body' => [
            "Lorem ipsum & dolor sit amet\nconsetetur < sadipscing elitr",
          ],
        ],
        'expected' => ["<p>Lorem ipsum &amp; dolor sit amet<br />\nconsetetur &lt; sadipscing elitr</p>\n"],
        'expected_plain' => ["Lorem ipsum & dolor sit amet\nconsetetur < sadipscing elitr\n"],
      ],
      'mixed' => [
        'message' => [
          'body' => [
            'Hello & World',
            'Hello & <strong>World</strong>',
            new FormattableMarkup('Hello &amp; World #@number', ['@number' => 2]),
            Markup::create('Hello &amp; <strong>World</strong>'),
          ],
        ],
        // Output is wrong due to https://www.drupal.org/project/swiftmailer/issues/3122389.
        'expected' => [
          "<p>Hello &amp; World</p>\n",
          "Hello & *World*\n",
          "<p>Hello &amp;amp; World #2</p>\n", "Hello &amp; <strong>World</strong>",
        ],
        'expected_plain' => [
          "Hello & World\n",
          "Hello & *World*\n",
          "Hello &amp; World #2",
          "Hello &amp; <strong>World</strong>",
        ],
      ],
    ];
  }

}
