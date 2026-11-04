<?php
/**
 * Per-language widget title and welcome message.
 *
 * Option bitbot_widget_copy = { "<code>": { "title", "welcome", "questions", "placeholder", "auto", "needs_review", "failed" } }.
 * auto true means a machine translation of the site-language text that is
 * re-translated when the base text changes; the first owner edit of a row
 * makes it auto false and it is never overwritten again. The site language
 * itself is not stored here: it is the base title and welcome message.
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Widget_Copy {

    const OPTION      = 'bitbot_widget_copy';
    const TITLE_MAX   = 60;
    const WELCOME_MAX = 400;
    const PLACEHOLDER_MAX = 50;
    const QUESTION_MAX = 80;
    const QUESTIONS_MAX = 8;

    /** Plain single-line text, capped. */
    public static function clean_title( $value ) {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        return self::cut( $value, self::TITLE_MAX );
    }

    /** The input hint: plain single-line text, capped. */
    public static function clean_placeholder( $value ) {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        return self::cut( $value, self::PLACEHOLDER_MAX );
    }

    /** Plain multi-line text, capped. */
    public static function clean_welcome( $value ) {
        $value = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) $value ) : trim( strip_tags( (string) $value ) );
        return self::cut( trim( $value ), self::WELCOME_MAX );
    }

    /** One suggested question: plain single-line text, capped. */
    public static function clean_question( $value ) {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        return self::cut( $value, self::QUESTION_MAX );
    }

    /**
     * The chat title the widget actually shows: the owner's title, else the
     * default. This is the text every other language is translated
     * from, so a site with no custom title still gets a translated header.
     *
     * @param mixed $custom Option value.
     */
    public static function base_title( $custom ) {
        $custom = trim( (string) $custom );
        if ( '' !== $custom ) {
            return $custom;
        }
        $default = 'Chat with us';
        return function_exists( '__' ) ? __( $default, 'bitbot' ) : $default;
    }

    /**
     * The built-in input hint. Added languages are translated
     * from this default, and the widget renders it when the owner set none.
     */
    public static function default_placeholder() {
        $line = 'Type your message...';
        return function_exists( '__' ) ? __( $line, 'bitbot' ) : $line;
    }

    /**
     * The built-in welcome message, per language where we have one, else the
     * English line. Added languages are translated from this default, never
     * from the owner's custom message: an owner writes their welcome for the
     * site language, every other language opens with the plain greeting
     * until the owner edits that row.
     *
     * @param string $code Language code.
     */
    public static function default_welcome( $code = 'en' ) {
        $greetings = array(
            'en' => 'Hi! How can I help you navigate our website today?',
            'hi' => 'नमस्ते! आज हमारी वेबसाइट पर आपकी क्या मदद हो सकती है?',
            'es' => '¡Hola! ¿Cómo puedo ayudarte a navegar por nuestro sitio web hoy?',
            'fr' => 'Bonjour ! Comment puis-je vous aider à naviguer sur notre site aujourd\'hui ?',
            'de' => 'Hallo! Wie kann ich Ihnen heute bei der Navigation auf unserer Website helfen?',
            'pt' => 'Olá! Como posso ajudá-lo a navegar em nosso site hoje?',
            'zh' => '您好！今天我能如何帮助您浏览我们的网站？',
            'ja' => 'こんにちは！本日はウェブサイトのナビゲーションをどのようにお手伝いできますか？',
            'ko' => '안녕하세요! 오늘 웹사이트 탐색에 어떻게 도움을 드릴까요?',
            'ar' => 'مرحباً! كيف يمكنني مساعدتك في تصفح موقعنا اليوم؟',
            'ru' => 'Привет! Как я могу помочь вам сегодня с навигацией по нашему сайту?',
        );
        $code = strtolower( substr( (string) $code, 0, 2 ) );
        return isset( $greetings[ $code ] ) ? $greetings[ $code ] : $greetings['en'];
    }

    /**
     * Base suggested questions as plain strings (the chip labels) from the
     * stored JSON option of { label, message } items.
     *
     * @param mixed $raw Option value (JSON string).
     */
    public static function base_questions( $raw ) {
        $decoded = json_decode( (string) $raw, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }
        $labels = array();
        foreach ( $decoded as $item ) {
            if ( is_array( $item ) ) {
                $labels[] = isset( $item['label'] ) && '' !== $item['label'] ? $item['label'] : ( isset( $item['message'] ) ? $item['message'] : '' );
            }
        }
        return self::clean_questions( $labels );
    }

    /** A list of questions: each cleaned, empties dropped, at most 8. */
    public static function clean_questions( $value ) {
        $out = array();
        if ( ! is_array( $value ) ) {
            return $out;
        }
        foreach ( $value as $q ) {
            if ( ! is_string( $q ) ) {
                continue;
            }
            $q = self::clean_question( $q );
            if ( '' !== $q ) {
                $out[] = $q;
            }
            if ( count( $out ) >= self::QUESTIONS_MAX ) {
                break;
            }
        }
        return $out;
    }

    private static function cut( $value, $max ) {
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
    }

    /**
     * Stored rows cleaned: only string codes with array rows, text fields
     * cleaned, auto a bool (missing means false: an owner row).
     *
     * @param mixed $raw Option value.
     */
    public static function normalize( $raw ) {
        $out = array();
        if ( ! is_array( $raw ) ) {
            return $out;
        }
        foreach ( $raw as $code => $row ) {
            if ( ! is_string( $code ) || '' === $code || ! is_array( $row ) ) {
                continue;
            }
            $out[ $code ] = self::row(
                self::clean_title( isset( $row['title'] ) ? $row['title'] : '' ),
                self::clean_welcome( isset( $row['welcome'] ) ? $row['welcome'] : '' ),
                self::clean_questions( isset( $row['questions'] ) ? $row['questions'] : array() ),
                self::clean_placeholder( isset( $row['placeholder'] ) ? $row['placeholder'] : '' ),
                ! empty( $row['auto'] ),
                ! empty( $row['needs_review'] ),
                ! empty( $row['failed'] )
            );
        }
        return $out;
    }

    /**
     * Codes that need a (re)translation: every non-site language in the
     * selection whose row is missing or auto. Owner rows are never listed.
     *
     * @param array  $selection Effective codes (site language first).
     * @param string $site_code Site language.
     * @param array  $stored    Normalized rows.
     */
    public static function codes_needing_translation( $selection, $site_code, $stored ) {
        $out = array();
        foreach ( (array) $selection as $code ) {
            if ( ! is_string( $code ) || $code === $site_code || in_array( $code, $out, true ) ) {
                continue;
            }
            if ( ! isset( $stored[ $code ] ) || ! empty( $stored[ $code ]['auto'] ) ) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Auto rows that lack a field the base text has (a title translated before
     * the default title counted, say). Owner rows are never listed.
     *
     * @param array  $selection Effective codes.
     * @param string $site_code Site language.
     * @param array  $stored    Normalized rows.
     * @param string $title     Base title.
     * @param string $welcome   Base welcome message.
     * @param array  $questions Base suggested questions.
     * @param string $placeholder Base input hint.
     */
    public static function incomplete_auto_codes( $selection, $site_code, $stored, $title, $welcome, $questions = array(), $placeholder = '' ) {
        $out = array();
        foreach ( (array) $selection as $code ) {
            if ( ! is_string( $code ) || $code === $site_code || in_array( $code, $out, true ) ) {
                continue;
            }
            if ( ! isset( $stored[ $code ] ) ) {
                // No row at all: a newly added language, translate it.
                $out[] = $code;
                continue;
            }
            if ( empty( $stored[ $code ]['auto'] ) ) {
                continue;
            }
            $row = $stored[ $code ];
            if ( ( '' !== (string) $title && '' === $row['title'] )
                || ( '' !== (string) $welcome && '' === $row['welcome'] )
                || ( ! empty( $questions ) && empty( $row['questions'] ) )
                || ( '' !== (string) $placeholder && '' === $row['placeholder'] ) ) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Rows without the automatic ones: what is left is the owner's own text.
     *
     * @param array $stored Normalized rows.
     * @return string[] The codes whose rows are machine translations.
     */
    public static function auto_codes( $stored ) {
        $out = array();
        foreach ( (array) $stored as $code => $row ) {
            if ( ! empty( $row['auto'] ) ) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Drop the machine-translated rows; owner rows are kept.
     *
     * @param array $stored Normalized rows.
     */
    public static function drop_auto_rows( $stored ) {
        $out = array();
        foreach ( (array) $stored as $code => $row ) {
            if ( empty( $row['auto'] ) ) {
                $out[ $code ] = $row;
            }
        }
        return $out;
    }

    /**
     * Store machine translations. Owner rows (auto false) are untouched, rows
     * for other codes are kept (nothing is pruned), empty results are skipped.
     *
     * @param array $stored  Normalized rows.
     * @param array $results code => array( title, welcome ).
     */
    public static function merge_auto( $stored, $results ) {
        foreach ( (array) $results as $code => $row ) {
            if ( ! is_string( $code ) || ! is_array( $row ) ) {
                continue;
            }
            if ( isset( $stored[ $code ] ) && empty( $stored[ $code ]['auto'] ) ) {
                continue;
            }
            $title     = self::clean_title( isset( $row['title'] ) ? $row['title'] : '' );
            $welcome   = self::clean_welcome( isset( $row['welcome'] ) ? $row['welcome'] : '' );
            $questions = self::clean_questions( isset( $row['questions'] ) ? $row['questions'] : array() );
            $placeholder = self::clean_placeholder( isset( $row['placeholder'] ) ? $row['placeholder'] : '' );
            if ( '' === $title && '' === $welcome && empty( $questions ) && '' === $placeholder ) {
                continue;
            }
            $stored[ $code ] = self::row( $title, $welcome, $questions, $placeholder, true, false, false );
        }
        return $stored;
    }

    /** A complete row. */
    private static function row( $title, $welcome, $questions, $placeholder, $auto, $needs_review, $failed ) {
        return array(
            'title'        => $title,
            'welcome'      => $welcome,
            'questions'    => $questions,
            'placeholder'  => $placeholder,
            'auto'         => (bool) $auto,
            'needs_review' => (bool) $needs_review,
            'failed'       => (bool) $failed,
        );
    }

    /**
     * Base text changed: owner rows (auto false) are flagged for review, their
     * text untouched. Auto rows and absent rows are left alone.
     *
     * @param array $stored Normalized rows.
     * @param array $codes  Codes to flag (effective non-site).
     */
    public static function flag_review( $stored, $codes ) {
        foreach ( (array) $codes as $code ) {
            if ( is_string( $code ) && isset( $stored[ $code ] ) && empty( $stored[ $code ]['auto'] ) ) {
                $stored[ $code ]['needs_review'] = true;
            }
        }
        return $stored;
    }

    /**
     * A translation that did not come back: keep whatever text exists, set
     * failed. An absent row is created empty (auto).
     *
     * @param array $stored Normalized rows.
     * @param array $codes  Codes that were needed and missing from the result.
     */
    public static function mark_failed( $stored, $codes ) {
        foreach ( (array) $codes as $code ) {
            if ( ! is_string( $code ) || '' === $code ) {
                continue;
            }
            if ( isset( $stored[ $code ] ) ) {
                $stored[ $code ]['failed'] = true;
            } else {
                $stored[ $code ] = self::row( '', '', array(), '', true, false, true );
            }
        }
        return $stored;
    }

    /**
     * Regenerate: the posted codes limited to the effective non-site selection.
     *
     * @param mixed  $requested Posted code list.
     * @param array  $selection Effective codes.
     * @param string $site_code Site language.
     */
    public static function regenerate_codes( $requested, $selection, $site_code ) {
        $out = array();
        if ( ! is_array( $requested ) ) {
            return $out;
        }
        foreach ( $requested as $code ) {
            if ( is_string( $code ) && $code !== $site_code && in_array( $code, (array) $selection, true ) && ! in_array( $code, $out, true ) ) {
                $out[] = $code;
            }
        }
        return $out;
    }

    /**
     * Store regenerated translations over ANY row, owner rows included, as auto
     * rows. A requested code with no usable result is marked failed.
     *
     * @param array $stored  Normalized rows.
     * @param array $results code => array( title, welcome, questions ).
     * @param array $codes   Codes that were requested.
     */
    public static function merge_regenerated( $stored, $results, $codes ) {
        foreach ( (array) $codes as $code ) {
            $row = ( is_array( $results ) && isset( $results[ $code ] ) && is_array( $results[ $code ] ) ) ? $results[ $code ] : array();
            $title     = self::clean_title( isset( $row['title'] ) ? $row['title'] : '' );
            $welcome   = self::clean_welcome( isset( $row['welcome'] ) ? $row['welcome'] : '' );
            $questions = self::clean_questions( isset( $row['questions'] ) ? $row['questions'] : array() );
            $placeholder = self::clean_placeholder( isset( $row['placeholder'] ) ? $row['placeholder'] : '' );
            if ( '' === $title && '' === $welcome && empty( $questions ) && '' === $placeholder ) {
                $stored = self::mark_failed( $stored, array( $code ) );
                continue;
            }
            $stored[ $code ] = self::row( $title, $welcome, $questions, $placeholder, true, false, false );
        }
        return $stored;
    }

    /**
     * Owner edits (POST widgetCopy): merge semantics per row and per field,
     * the row becomes auto false. Only codes in $allowed are accepted.
     *
     * @param array $stored  Normalized rows.
     * @param mixed $posted  code => array( title?, welcome? ).
     * @param array $allowed Codes the owner may edit (effective non-site).
     */
    public static function merge_owner( $stored, $posted, $allowed ) {
        if ( ! is_array( $posted ) ) {
            return $stored;
        }
        foreach ( $posted as $code => $row ) {
            if ( ! is_string( $code ) || ! in_array( $code, (array) $allowed, true ) || ! is_array( $row ) ) {
                continue;
            }
            $prev = isset( $stored[ $code ] ) ? $stored[ $code ] : array( 'title' => '', 'welcome' => '', 'questions' => array(), 'placeholder' => '' );
            $prev_q = isset( $prev['questions'] ) ? $prev['questions'] : array();
            $stored[ $code ] = self::row(
                array_key_exists( 'title', $row ) ? self::clean_title( $row['title'] ) : $prev['title'],
                array_key_exists( 'welcome', $row ) ? self::clean_welcome( $row['welcome'] ) : $prev['welcome'],
                array_key_exists( 'questions', $row ) ? self::clean_questions( $row['questions'] ) : $prev_q,
                array_key_exists( 'placeholder', $row ) ? self::clean_placeholder( $row['placeholder'] ) : ( isset( $prev['placeholder'] ) ? $prev['placeholder'] : '' ),
                false,
                false,
                false
            );
        }
        return $stored;
    }

    /**
     * The copy for one language: its row when it has text, else the base.
     *
     * @param string $code      Language.
     * @param string $site_code Site language.
     * @param array  $stored    Normalized rows.
     * @param string $title     Base title.
     * @param string $welcome   Base welcome message.
     * @param array  $questions Base suggested questions (strings).
     * @param string $placeholder Base input hint.
     */
    public static function for_language( $code, $site_code, $stored, $title, $welcome, $questions = array(), $placeholder = '' ) {
        $row = ( $code !== $site_code && isset( $stored[ $code ] ) ) ? $stored[ $code ] : array();
        return array(
            'questions' => ( ! empty( $row['questions'] ) && is_array( $row['questions'] ) ) ? $row['questions'] : array_values( (array) $questions ),
            'placeholder' => ( isset( $row['placeholder'] ) && '' !== $row['placeholder'] ) ? $row['placeholder'] : (string) $placeholder,
            'title'   => ( isset( $row['title'] ) && '' !== $row['title'] ) ? $row['title'] : $title,
            'welcome' => ( isset( $row['welcome'] ) && '' !== $row['welcome'] ) ? $row['welcome'] : $welcome,
        );
    }

    /**
     * Widget config copy map for the selected languages.
     *
     * @param array  $codes     Effective codes.
     * @param string $site_code Site language.
     * @param array  $stored    Normalized rows.
     */
    public static function config_map( $codes, $site_code, $stored, $title, $welcome, $questions = array(), $placeholder = '' ) {
        $out = array();
        foreach ( (array) $codes as $code ) {
            $out[ $code ] = self::for_language( $code, $site_code, $stored, $title, $welcome, $questions, $placeholder );
        }
        return $out;
    }
}
