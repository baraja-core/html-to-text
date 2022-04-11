<?php

declare(strict_types=1);

namespace Baraja\HtmlToText;


use Baraja\Url\Url;

final class Html2Text
{
	private const LINKS_LOCALE = [
		'cs' => 'Odkazy',
		'sk' => 'Odkazy',
		'en' => 'Links',
		'de' => 'Links',
		'sp' => 'Enlaces',
		'hu' => 'Referenciák',
		'cn' => '友情链接',
		'jp' => 'リンク集',
	];

	/**
	 * List of preg* regular expression patterns to search for and replace.
	 *
	 * @var string[]
	 */
	private array $basicRules = [
		"/\r/" => '',                                           // Non-legal carriage return
		"/[\n\t]+/" => ' ',                                     // Newlines and tabs
		'/[ ]{2,}/' => ' ',                                     // Runs of spaces, pre-handling
		'/<title[^>]*>.*?<\/title>/i' => '',                    // <script>s -- which strip_tags supposedly has problems with
		'/<script[^>]*>.*?<\/script>/i' => '',                  // <script>s -- which strip_tags supposedly has problems with
		'/<style[^>]*>.*?<\/style>/i' => '',                    // <style>s -- which strip_tags supposedly has problems with
		'/<p[^>]*>/i' => "\n\n\t",                              // <P>
		'/<br[^>]*>/i' => "\n",                                 // <br>
		'/<i[^>]*>(.*?)<\/i>/i' => '_\\1_',                     // <i>
		'/<em[^>]*>(.*?)<\/em>/i' => '_\\1_',                   // <em>
		'/(<ul[^>]*>|<\/ul>)/i' => "\n\n",                      // <ul> and </ul>
		'/(<ol[^>]*>|<\/ol>)/i' => "\n\n",                      // <ol> and </ol>
		'/<li[^>]*>(.*?)<\/li>/i' => "\t* \\1\n",               // <li> and </li>
		'/<li[^>]*>/i' => "\n\t* ",                             // <li>
		'/<hr[^>]*>/i' => "\n-------------------------\n",      // <hr>
		'/(<table[^>]*>|<\/table>)/i' => "\n",                  // <table> and </table>
		'/(<tr[^>]*>|<\/tr>)/i' => "\n",                        // <tr> and </tr>
		'/<td[^>]*>(.*?)<\/td>/i' => '\\1',                     // <td> and </td>
		'/&(nbsp|#160);/i' => ' ',                              // Non-breaking space
		'/&(quot|rdquo|ldquo|#8220|#8221|#147|#148);/i' => '"', // Double quotes
		'/&(apos|rsquo|lsquo|#8216|#8217);/i' => "'",           // Single quotes
		'/&gt;/i' => '>',                                       // Greater-than
		'/&lt;/i' => '<',                                       // Less-than
		'/&(amp|#38);/i' => '&',                                // Ampersand
		'/&(copy|#169);/i' => '(c)',                            // Copyright
		'/&(trade|#8482|#153);/i' => '(tm)',                    // Trademark
		'/&(reg|#174);/i' => '(R)',                             // Registered
		'/&(mdash|#151|#8212);/i' => '--',                      // mdash
		'/&(ndash|minus|#8211|#8722);/i' => '-',                // ndash
		'/&(bull|#149|#8226);/i' => '*',                        // Bullet
		'/&(pound|#163);/i' => 'ï¿½',                           // Pound sign
		'/&(euro|#8364);/i' => 'EUR',                           // Euro sign
		'/&[^&;]+;/i' => '',                                    // Unknown/unhandled entities
		'/[ ]{2,64}/' => ' ',                                   // Runs of spaces, post-handling
	];

	/** @var string[] */
	private array $searchReplaceCallback = [
		'/<h[123][^>]*>(.*?)<\/h[123]>/i',           // H1 - H3
		'/<h[456][^>]*>(.*?)<\/h[456]>/i',           // H4 - H6
		'/<b[^>]*>([^<]+)<\/b>/i',                   // <b>
		'/<strong[^>]*>(.*?)<\/strong>/i',           // <strong>
		'/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/i', // <a href="">
		'/<th[^>]*>(.*?)<\/th>/i',                   // <th> and </th>
	];

	/**
	 * Maximum width of the formatted text, in columns.
	 *
	 * Set this value to 0 (or less) to ignore word wrapping
	 * and not constrain text to a fixed-width column.
	 */
	private int $width = 120;

	/** Contains a list of HTML tags to allow in the resulting text. */
	private ?string $allowedTags = null;

	/** Contains the base URL that relative links should resolve to. */
	private ?string $url = null;

	/** Contains URL addresses from links to be rendered in plain text. */
	private string $linkList = '';

	/**
	 * Number of valid links detected in the text, used for plain text
	 * display (rendered similar to footnotes).
	 */
	private int $linkCounter = 0;


	/**
	 * @param mixed[]|null $configuration
	 */
	public function __construct(array $configuration = null)
	{
		$configuration ??= [];

		$this->setBaseUrl(
			isset($configuration['baseUrl'])
				? (string) $configuration['baseUrl']
				: null,
		);

		if (isset($configuration['width'])) {
			$this->width = (int) $configuration['width'];
		}
		if (isset($configuration['allowedTags'])) {
			$this->allowedTags = (string) $configuration['allowedTags'];
		}
	}


	public static function convertHTMLToPlainText(string $html, string $locale): string
	{
		return trim(
			str_replace(
				'^_#%^',
				'$',
				(new self)->convert(str_replace('$', '^_#%^', $html), $locale),
			),
		);
	}


	/**
	 * Sets a base URL to handle relative links.
	 */
	public function setBaseUrl(string $url = null): void
	{
		if ($url === null) {
			$this->url = isset($_SERVER['HTTP_HOST'])
				? Url::get()->getNetteUrl()->getHostUrl()
				: '';
		} else {
			// Strip any trailing slashes for consistency
			// (relative URLs may already start with a slash like "/file.html")
			if (str_ends_with($url, '/')) {
				$url = substr($url, 0, -1);
			}

			$this->url = $url;
		}
	}


	/**
	 * Workhorse function that does actual conversion.
	 *
	 * First performs custom tag replacement specified by $search and
	 * $replace arrays. Then strips any remaining HTML tags, reduces whitespace
	 * and newlines to a readable format, and word wraps the text to
	 * $width characters.
	 */
	public function convert(string $text, string $locale): string
	{
		// Variables used for building the link list
		$this->linkCounter = 0;
		$this->linkList = '';

		$text = trim(stripslashes($text));

		// Run our defined search-and-replace
		$text = $this->basicReplace($text);

		// Replace non-trivial patterns
		foreach ($this->searchReplaceCallback as $regexp) {
			$text = (string) preg_replace_callback($regexp, function (array $matches): string {
				if ($matches === []) {
					return '?';
				}

				$result = $matches[1] ?? null;
				if (preg_match('/<h[123][^>]*>/i', $matches[0]) === 1) { // H1 - H3
					$result = mb_strtoupper("\n\n" . $matches[1] . "\n\n");
				} elseif (preg_match('/<h[456][^>]*>/i', $matches[0]) === 1) { // H4 - H6
					$result = ucwords("\n\n" . $matches[1] . "\n\n");
				} elseif (preg_match('/<(b|strong)[^>]*>/i', $matches[0]) === 1) { // B & STRONG
					$result = mb_strtoupper($matches[1]);
				} elseif (preg_match('/<a\s+[^>]*>/i', $matches[0]) === 1) { // A
					$result = $this->buildLinkList($matches[1], $matches[2]);
				} elseif (preg_match('/<th[^>]*>/i', $matches[0]) === 1) { // TH
					$result = mb_strtoupper("\t\t" . $matches[1] . "\n");
				}

				return $result ?? '';
			}, $text);
		}

		// Strip any other HTML tags
		$text = strip_tags($text, (string) $this->allowedTags);

		// Bring down number of empty lines to 2 max
		$text = (string) preg_replace("/\n\\s+\n/", "\n\n", $text);
		$text = (string) preg_replace("/[\n]{3,}/", "\n\n", $text);

		/** @phpstan-ignore-next-line */
		if ($this->linkList !== '') { // Add link list
			$text .= "\n\n" . (self::LINKS_LOCALE[$locale] ?? 'Links') . ":\n-------\n" . $this->linkList;
		}

		$text = (string) preg_replace('/\n[\t ]+/', "\n", trim($text)); // remove line-start whitespaces
		$text = (string) preg_replace('/(\S)[\t ]+$/m', '$1', trim($text)); // remove line-end whitespaces

		if ($this->width > 0) {
			$text = wordwrap($text, $this->width);
		}

		return $text;
	}


	public function addRule(string $searchPattern, string $replacePattern): self
	{
		$this->basicRules[$searchPattern] = $replacePattern;

		return $this;
	}


	/**
	 * Helper function called by preg_replace() on link replacement.
	 *
	 * Maintains an internal list of links to be displayed at the end of the
	 * text, with numeric indices to the original point in the text they
	 * appeared. Also makes an effort at identifying and handling absolute
	 * and relative links.
	 *
	 * @param string $link URL of the link
	 * @param string $display Part of the text to associate number with
	 */
	private function buildLinkList(string $link, string $display): string
	{
		$link = trim($link);
		if (preg_match('/^(https?|mailto):/i', $link) === 1) {
			$this->linkCounter++;
			$this->linkList .= '[' . $this->linkCounter . "] $link\n";
			$additional = ' [' . $this->linkCounter . ']';
		} elseif (str_starts_with($link, 'javascript:')) {
			$additional = '';
		} else {
			$this->linkCounter++;
			$this->linkList .= '[' . $this->linkCounter . '] ' . $this->url;
			if (isset($link[0]) && $link[0] !== '/') {
				$this->linkList .= '/';
			}
			$this->linkList .= $link . "\n";
			$additional = ' [' . $this->linkCounter . ']';
		}

		return $display . $additional;
	}


	private function basicReplace(string $haystack): string
	{
		$search = [];
		$replace = [];
		foreach ($this->basicRules as $searchItem => $replaceItem) {
			$search[] = $searchItem;
			$replace[] = $replaceItem;
		}

		return (string) preg_replace($search, $replace, $haystack);
	}
}
