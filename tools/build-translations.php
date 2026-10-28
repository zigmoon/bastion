<?php
/**
 * Builds the translation catalogue from the source strings.
 *
 * Outputs, in Bastion/languages/:
 *   bastion.pot            the template (English)
 *   bastion-fr_FR.po/.mo   French
 *   bastion-es_ES.po/.mo   Spanish
 *
 * Plural calls (_n) are emitted with msgid_plural and a two-form msgstr,
 * and are compiled into the MO as the two forms joined by NUL, which is what
 * WordPress reads back.
 *
 * The build fails when a string has no translation or when the catalogue
 * carries an entry the source no longer has, so the files can never drift.
 *
 * Run with: php tools/build-translations.php
 *
 * @package Bastion
 */

$root    = dirname( __DIR__ );

// The version embedded in the catalogue header is read from the plugin, so a
// release can never ship a catalogue that announces the previous version.
$header  = (string) file_get_contents( $root . '/Bastion/bastion.php' );
$version = preg_match( '/^\s*\*\s*Version:\s*([\d.]+)/m', $header, $match ) ? $match[1] : '0.0.0';
$domain  = 'bastion';
$repo    = 'https://github.com/zigmoon/bastion/issues';

$locales = array(
	'fr_FR' => require __DIR__ . '/fr-translations.php',
	'es_ES' => require __DIR__ . '/es-translations.php',
);

$files = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/Bastion', FilesystemIterator::SKIP_DOTS )
);

$sources = '#\b(__|esc_html__|esc_attr__|esc_html_e|esc_attr_e|_e|_x)\s*\(\s*([\'"])((?:\\\\.|(?!\2).)*)\2#s';
$plurals = '#\b_n\s*\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3#s';

$strings = array();
$plural  = array();
$refs    = array();

foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}

	$path   = $file->getPathname();
	$source = (string) file_get_contents( $path );
	$rel    = 'Bastion/' . ltrim( str_replace( '\\', '/', substr( $path, strlen( $root . '/Bastion/' ) ) ), '/' );

	if ( preg_match_all( $sources, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches as $match ) {
			$text = stripcslashes( $match[3][0] );
			$line = substr_count( substr( $source, 0, $match[0][1] ), "\n" ) + 1;

			$strings[ $text ]                    = true;
			$refs[ $text ][ $rel . ':' . $line ] = true;
		}
	}

	if ( preg_match_all( $plurals, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches as $match ) {
			$single = stripcslashes( $match[2][0] );
			$many   = stripcslashes( $match[4][0] );
			$line   = substr_count( substr( $source, 0, $match[0][1] ), "\n" ) + 1;

			$plural[ $single ]                   = $many;
			$refs[ $single ][ $rel . ':' . $line ] = true;
		}
	}
}

ksort( $strings, SORT_STRING );
ksort( $plural, SORT_STRING );
ksort( $refs, SORT_STRING );

$failed = false;

foreach ( $locales as $locale => $catalogue ) {
	$missing = array();

	foreach ( array_keys( $strings ) as $text ) {
		if ( ! isset( $catalogue[ $text ] ) || ! is_string( $catalogue[ $text ] ) ) {
			$missing[] = $text;
		}
	}

	foreach ( $plural as $single => $many ) {
		$entry = $catalogue[ $single ] ?? null;

		if ( ! is_array( $entry ) || 2 !== count( $entry ) || ! isset( $entry[0], $entry[1] ) ) {
			$missing[] = $single . ' (plural)';
		}
	}

	if ( array() !== $missing ) {
		$failed = true;
		echo "Missing {$locale} translations:\n";
		foreach ( $missing as $text ) {
			echo '  - ' . $text . "\n";
		}
	}

	$unused = array();

	foreach ( array_keys( $catalogue ) as $text ) {
		if ( ! isset( $strings[ $text ] ) && ! isset( $plural[ $text ] ) ) {
			$unused[] = $text;
		}
	}

	if ( array() !== $unused ) {
		$failed = true;
		echo "Obsolete {$locale} entries:\n";
		foreach ( $unused as $text ) {
			echo '  - ' . $text . "\n";
		}
	}
}

if ( $failed ) {
	exit( 1 );
}

/**
 * Escapes a string for a PO file.
 *
 * @param string $value Raw value.
 */
function dup_po_escape( string $value ): string {
	return strtr(
		$value,
		array(
			'\\' => '\\\\',
			'"'  => '\\"',
			"\n" => '\\n',
			"\r" => '\\r',
			"\t" => '\\t',
		)
	);
}

/**
 * Builds a PO header.
 *
 * @param string $locale Locale code, or an empty string for the template.
 */
function dup_po_header( string $locale ): string {
	global $version, $repo, $domain;

	$header = "msgid \"\"\n"
		. "msgstr \"\"\n"
		. "\"Project-Id-Version: Bastion {$version}\\n\"\n"
		. "\"Report-Msgid-Bugs-To: {$repo}\\n\"\n"
		. "\"POT-Creation-Date: 2026-10-28T00:00:00+00:00\\n\"\n";

	if ( '' !== $locale ) {
		$team = 'fr_FR' === $locale ? 'Français' : 'Español';

		$header .= "\"PO-Revision-Date: 2026-10-28T00:00:00+00:00\\n\"\n"
			. "\"Last-Translator: zigmoon\\n\"\n"
			. "\"Language-Team: {$team}\\n\"\n"
			. "\"Language: {$locale}\\n\"\n"
			. "\"Plural-Forms: nplurals=2; plural=(n > 1);\\n\"\n";
	}

	$header .= "\"MIME-Version: 1.0\\n\"\n"
		. "\"Content-Type: text/plain; charset=UTF-8\\n\"\n"
		. "\"Content-Transfer-Encoding: 8bit\\n\"\n"
		. "\"X-Domain: {$domain}\\n\"";

	return $header;
}

/**
 * One PO block: reference line plus msgid, optional plural, and translations.
 *
 * @param string        $text        Singular source string.
 * @param string        $reference   Reference comment.
 * @param string[]|null $translation Two forms, or null for the template.
 * @param string|null   $many        Plural source string.
 */
function dup_po_block( string $text, string $reference, ?array $translation, ?string $many = null ): string {
	$block = "\n" . $reference . 'msgid "' . dup_po_escape( $text ) . "\"\n";

	if ( null !== $many ) {
		$block .= 'msgid_plural "' . dup_po_escape( $many ) . "\"\n";

		if ( null === $translation ) {
			return $block . "msgstr[0] \"\"\nmsgstr[1] \"\"\n";
		}

		return $block . 'msgstr[0] "' . dup_po_escape( $translation[0] ) . "\"\n"
			. 'msgstr[1] "' . dup_po_escape( $translation[1] ) . "\"\n";
	}

	if ( null === $translation ) {
		return $block . "msgstr \"\"\n";
	}

	return $block . 'msgstr "' . dup_po_escape( $translation[0] ) . "\"\n";
}

/**
 * Compiles a PO catalogue to the binary MO format.
 *
 * @param array<string, string> $entries msgid => msgstr.
 * @param string                $path    Destination.
 */
function dup_compile_mo( array $entries, string $path ): void {
	uksort( $entries, 'strcmp' );

	$count         = count( $entries );
	$data          = '';
	$original_rows = '';
	$trans_rows    = '';
	$string_offset = 28 + ( 16 * $count );

	foreach ( $entries as $msgid => $msgstr ) {
		$blob           = $msgid . "\0";
		$original_rows .= pack( 'V', strlen( $blob ) - 1 ) . pack( 'V', $string_offset + strlen( $data ) );
		$data          .= $blob;
	}

	foreach ( $entries as $msgid => $msgstr ) {
		$blob        = $msgstr . "\0";
		$trans_rows .= pack( 'V', strlen( $blob ) - 1 ) . pack( 'V', $string_offset + strlen( $data ) );
		$data       .= $blob;
	}

	$binary = pack( 'V', 0x950412de )
		. pack( 'V', 0 )
		. pack( 'V', $count )
		. pack( 'V', 28 )
		. pack( 'V', 28 + ( 8 * $count ) )
		. pack( 'V', 0 )
		// WordPress derives the start of the string area from hash_addr, so it
		// must point past both tables even when the hash table itself is empty.
		. pack( 'V', 28 + ( 16 * $count ) )
		. $original_rows
		. $trans_rows
		. $data;

	file_put_contents( $path, $binary );
}

if ( ! is_dir( $root . '/Bastion/languages' ) ) {
	mkdir( $root . '/Bastion/languages', 0o755, true );
}

$pot = "# Copyright (C) 2026 zigmoon\n# Distributed under the GPL-2.0-or-later license.\n" . dup_po_header( '' );

foreach ( array_keys( $strings ) as $text ) {
	$pot .= dup_po_block( $text, '#: ' . implode( ' ', array_keys( $refs[ $text ] ) ) . "\n", null );
}

foreach ( $plural as $single => $many ) {
	$pot .= dup_po_block( $single, '#: ' . implode( ' ', array_keys( $refs[ $single ] ) ) . "\n", null, $many );
}

file_put_contents( $root . '/Bastion/languages/bastion.pot', $pot );

foreach ( $locales as $locale => $catalogue ) {
	$name = 'fr_FR' === $locale ? 'French' : 'Spanish';
	$team = 'fr_FR' === $locale ? 'Français' : 'Español';

	$po = "# {$name} translation for Bastion.\n# Distributed under the GPL-2.0-or-later license.\n" . dup_po_header( $locale );

	foreach ( array_keys( $strings ) as $text ) {
		$po .= dup_po_block( $text, '#: ' . implode( ' ', array_keys( $refs[ $text ] ) ) . "\n", array( $catalogue[ $text ] ) );
	}

	foreach ( $plural as $single => $many ) {
		$po .= dup_po_block( $single, '#: ' . implode( ' ', array_keys( $refs[ $single ] ) ) . "\n", $catalogue[ $single ], $many );
	}

	file_put_contents( $root . '/Bastion/languages/bastion-' . $locale . '.po', $po );

	$mo_header = "Project-Id-Version: Bastion {$version}\n"
		. "Report-Msgid-Bugs-To: {$repo}\n"
		. "PO-Revision-Date: 2026-10-28T00:00:00+00:00\n"
		. "Last-Translator: zigmoon\n"
		. "Language-Team: {$team}\n"
		. "Language: {$locale}\n"
		. "MIME-Version: 1.0\n"
		. "Content-Type: text/plain; charset=UTF-8\n"
		. "Content-Transfer-Encoding: 8bit\n"
		. "Plural-Forms: nplurals=2; plural=(n > 1);\n"
		. "X-Domain: {$domain}\n";

	$entries = array( '' => $mo_header );

	foreach ( array_keys( $strings ) as $text ) {
		$entries[ $text ] = $catalogue[ $text ];
	}

	foreach ( array_keys( $plural ) as $single ) {
		$entries[ $single ] = implode( "\0", $catalogue[ $single ] );
	}

	uksort( $entries, 'strcmp' );

	dup_compile_mo( $entries, $root . '/Bastion/languages/bastion-' . $locale . '.mo' );

	// Read the compiled catalogue back: a broken MO is worse than no MO at all.
	$raw    = (string) file_get_contents( $root . '/Bastion/languages/bastion-' . $locale . '.mo' );
	$header = unpack( 'Vmagic/Vrevision/Vcount/Voriginals/Vtranslations/Vhash_size/Vhash_offset', $raw );

	if ( 0x950412de !== $header['magic'] || count( $entries ) !== $header['count'] ) {
		echo "MO verification failed for {$locale}: bad header.\n";
		exit( 1 );
	}

	$read = array();

	for ( $i = 0; $i < $header['count']; $i++ ) {
		$original    = unpack( 'Vlength/Voffset', substr( $raw, $header['originals'] + ( $i * 8 ), 8 ) );
		$translation = unpack( 'Vlength/Voffset', substr( $raw, $header['translations'] + ( $i * 8 ), 8 ) );

		$read[ substr( $raw, $original['offset'], $original['length'] ) ] = substr( $raw, $translation['offset'], $translation['length'] );
	}

	if ( $read !== $entries ) {
		echo "MO verification failed for {$locale}: entries differ.\n";
		exit( 1 );
	}
}

echo 'Wrote ' . count( $strings ) . ' strings + ' . count( $plural ) . ' plural strings for '
	. implode( ', ', array_keys( $locales ) ) . " (pot + po + mo, verified).\n";
