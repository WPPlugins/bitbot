<?php
/**
 * Field markup for the [bitbot_form] shortcode.
 *
 * Prints the same element per field type as assets/js/widget-form.js prints for
 * the pre-chat gate: text, email, tel, textarea, select, a radio fieldset with
 * a legend, a checkbox fieldset with one checkbox per option. Each control has
 * data-field, aria-required when required, and a hidden message element that
 * the form script fills and links with aria-describedby. No native `required`
 * attribute: the form is novalidate and the script shows the errors itself.
 *
 * Name and email are always present and always required (the submit endpoint
 * rejects a lead without them).
 *
 * @package BitBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BitBot_Form_Markup {

    /**
     * Fields as the visitor sees them: name and email forced on and required.
     *
     * @param array $fields FormFieldSchema rows.
     * @return array
     */
    public static function normalize_fields( $fields ) {
        $out = array();
        foreach ( (array) $fields as $f ) {
            if ( ! is_array( $f ) || empty( $f['name'] ) ) {
                continue;
            }
            if ( 'name' === $f['name'] ) {
                $f['required'] = true;
                $f['type']     = 'text';
            }
            if ( 'email' === $f['name'] ) {
                $f['required'] = true;
                $f['type']     = 'email';
            }
            $out[] = $f;
        }
        $names = array_column( $out, 'name' );
        $head  = array();
        if ( ! in_array( 'name', $names, true ) ) {
            $head[] = array( 'name' => 'name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'placeholder' => 'Your name' );
        }
        if ( ! in_array( 'email', $names, true ) ) {
            $head[] = array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'placeholder' => 'you@example.com' );
        }
        return array_merge( $head, $out );
    }

    /** The field types the widget and the shortcode can render. */
    const FIELD_TYPES = array( 'text', 'email', 'phone', 'textarea', 'select', 'radio', 'checkbox' );

    /**
     * Form fields as submitted by the admin screen, reduced to the keys and
     * shapes a FormFieldSchema row may have: a plain name, text label and
     * placeholder, a known type, a boolean required, and text options only for
     * the types that have options. Anything else is dropped.
     *
     * @param mixed $fields Decoded JSON from the request.
     * @return array
     */
    public static function sanitize_fields( $fields ) {
        $out = array();
        foreach ( (array) $fields as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $name = preg_replace( '/[^A-Za-z0-9_-]/', '', sanitize_text_field( (string) ( $f['name'] ?? '' ) ) );
            if ( '' === $name ) {
                continue;
            }
            $type = (string) ( $f['type'] ?? 'text' );
            if ( ! in_array( $type, self::FIELD_TYPES, true ) ) {
                $type = 'text';
            }
            $required = $f['required'] ?? false;
            $row      = array(
                'name'     => $name,
                'label'    => sanitize_text_field( (string) ( $f['label'] ?? $name ) ),
                'type'     => $type,
                'required' => true === $required || 1 === $required || '1' === $required || 'true' === $required,
            );
            if ( isset( $f['placeholder'] ) && '' !== (string) $f['placeholder'] ) {
                $row['placeholder'] = sanitize_text_field( (string) $f['placeholder'] );
            }
            if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) ) {
                $opts           = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
                $row['options'] = array_values( array_filter( array_map( function ( $o ) {
                    return sanitize_text_field( (string) $o );
                }, $opts ), 'strlen' ) );
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function id_part( $name ) {
        return preg_replace( '/[^A-Za-z0-9_-]/', '_', (string) $name );
    }

    private static function options( $field ) {
        $opts = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();
        return array_values( array_filter( array_map( 'strval', $opts ), function ( $o ) { return '' !== $o; } ) );
    }

    /**
     * wp_kses allow-list matching exactly the markup render_field() produces.
     * Output is already escaped per value; this is the belt-and-braces gate.
     *
     * @return array
     */
    public static function allowed_html() {
        $common = array(
            'id' => true, 'class' => true, 'name' => true, 'placeholder' => true, 'type' => true,
            'data-field' => true, 'data-field-row' => true, 'data-field-type' => true, 'data-error-id' => true,
            'aria-required' => true, 'aria-hidden' => true, 'hidden' => true, 'rows' => true, 'value' => true, 'for' => true,
        );
        return array(
            'div'      => $common,
            'fieldset' => $common,
            'legend'   => $common,
            'label'    => $common,
            'span'     => $common,
            'p'        => $common,
            'input'    => $common,
            'textarea' => $common,
            'select'   => $common,
            'option'   => $common,
        );
    }

    /**
     * @param array  $fields    Normalized fields.
     * @param string $id_prefix Unique per form instance, e.g. "bitbot-field-2".
     * @return string
     */
    public static function render_fields( $fields, $id_prefix ) {
        $html = '';
        foreach ( $fields as $f ) {
            $html .= self::render_field( $f, $id_prefix );
        }
        return $html;
    }

    private static function render_field( $f, $id_prefix ) {
        $name     = (string) $f['name'];
        $type     = isset( $f['type'] ) ? (string) $f['type'] : 'text';
        $required = ! empty( $f['required'] );
        $label    = isset( $f['label'] ) && '' !== $f['label'] ? $f['label'] : $name;
        $ph       = isset( $f['placeholder'] ) ? (string) $f['placeholder'] : '';
        $id       = $id_prefix . '-' . self::id_part( $name );
        $err_id   = $id . '-error';
        $mark     = $required ? '<span class="bitbot-req" aria-hidden="true"> *</span>' : '';
        $err      = '<p class="bitbot-form-field-msg" id="' . esc_attr( $err_id ) . '" hidden></p>';
        $common   = ' id="' . esc_attr( $id ) . '" data-field="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"'
            . ( $required ? ' aria-required="true"' : '' )
            . ( '' !== $ph ? ' placeholder="' . esc_attr( $ph ) . '"' : '' )
            . ' data-error-id="' . esc_attr( $err_id ) . '"';

        if ( 'radio' === $type || 'checkbox' === $type ) {
            $items = '';
            foreach ( self::options( $f ) as $i => $opt ) {
                $oid    = $id . '-' . $i;
                $items .= '<label class="bitbot-form-choice" for="' . esc_attr( $oid ) . '">'
                    . '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $oid ) . '" data-field="' . esc_attr( $name ) . '"'
                    . ' name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt ) . '" data-error-id="' . esc_attr( $err_id ) . '">'
                    . '<span>' . esc_html( $opt ) . '</span></label>';
            }
            return '<fieldset class="bitbot-form-field bitbot-form-group" data-field-row="' . esc_attr( $name ) . '"'
                . ' data-field-type="' . esc_attr( $type ) . '"' . ( $required ? ' aria-required="true"' : '' ) . ' id="' . esc_attr( $id ) . '">'
                . '<legend class="bitbot-form-legend">' . esc_html( $label ) . $mark . '</legend>'
                . $items . $err . '</fieldset>';
        }

        $lab = '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . $mark . '</label>';
        if ( 'textarea' === $type ) {
            $control = '<textarea rows="4"' . $common . '></textarea>';
        } elseif ( 'select' === $type ) {
            $control = '<select' . $common . '><option value="">' . esc_html( '' !== $ph ? $ph : 'Select...' ) . '</option>';
            foreach ( self::options( $f ) as $opt ) {
                $control .= '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
            }
            $control .= '</select>';
        } else {
            $input   = ( 'email' === $type ) ? 'email' : ( ( 'phone' === $type ) ? 'tel' : 'text' );
            $control = '<input type="' . $input . '"' . $common . '>';
        }
        return '<div class="bitbot-form-field" data-field-row="' . esc_attr( $name ) . '">' . $lab . $control . $err . '</div>';
    }
}
