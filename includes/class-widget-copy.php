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

    /**

     * The API's translation rules an automatic row was made under. Bumped when

     * the TranslateWidgetCopy prompt changes in a way old rows must follow:

     * 2 = the welcome line is written without the speaker's gender (a female

     * voice was reading "मदद कर सकता हूं", 2026-11-09). An auto row made under

     * older rules is remade on the next settings read or save.

     */

    const AUTO_RULES = 2;
    const TITLE_MAX   = 60;
    const WELCOME_MAX = 400;
    const PLACEHOLDER_MAX = 50;
    const QUESTION_MAX = 80;
    const QUESTIONS_MAX = 8;

    /** Plain single-line text, capped. */
    public static function clean_title( $value ) {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        return self::cut( $value, self::TITLE_MAX );
    }

    /** The input hint: plain single-line text, capped. */
    public static function clean_placeholder( $value ) {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
        $value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
        return self::cut( $value, self::PLACEHOLDER_MAX );
    }

    /** Plain multi-line text, capped. */
    public static function clean_welcome( $value ) {
        $value = function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) $value ) : trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
        return self::cut( trim( $value ), self::WELCOME_MAX );
    }

    /** One suggested question: plain single-line text, capped. */
    public static function clean_question( $value ) {
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( preg_replace( '/<[^>]*>/', '', (string) $value ) );
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
    public static function base_title( $custom, $code = 'en' ) {
        $custom = trim( (string) $custom );
        if ( '' !== $custom ) {
            return $custom;
        }
        return self::default_title( $code );
    }

    /** Languages written right to left: the widget lays itself out for them, not for the page. */
    const RTL = array( 'ar', 'fa', 'he', 'ur', 'ps', 'sd', 'ug', 'yi', 'dv', 'ckb', 'ks' );

    public static function is_rtl( $code ) {
        return in_array( strtolower( substr( (string) $code, 0, 2 ) ), self::RTL, true );
    }

    /**
     * The built-in header title in the site's language where we have one (a
     * Persian site opened with "Chat with us", 2026-11-06), else the cached
     * machine translation the admin fetched once (site_defaults), else English.
     *
     * @param string $code Language code.
     */
    public static function default_title( $code = 'en' ) {
        $titles = array(
            'en' => 'Chat with us',
            'fa' => 'با ما گفتگو کنید',
            'ar' => 'تحدث معنا',
            'he' => 'דברו איתנו',
            'ur' => 'ہم سے بات کریں',
            'hi' => 'हमसे बात करें',
            'es' => 'Chatea con nosotros',
            'fr' => 'Discutez avec nous',
            'de' => 'Chatten Sie mit uns',
            'pt' => 'Fale conosco',
            'it' => 'Chatta con noi',
            'nl' => 'Chat met ons',
            'pl' => 'Porozmawiaj z nami',
            'tr' => 'Bizimle sohbet edin',
            'ru' => 'Напишите нам',
            'uk' => 'Напишіть нам',
            'el' => 'Μιλήστε μαζί μας',
            'zh' => '与我们聊天',
            'ja' => 'チャットで相談',
            'ko' => '채팅 문의',
            'id' => 'Chat dengan kami',
            'vi' => 'Trò chuyện với chúng tôi',
            'th' => 'แชทกับเรา',
            'bn' => 'আমাদের সাথে চ্যাট করুন',
            'ta' => 'எங்களுடன் அரட்டையடிக்கவும்',
            'pa' => 'ਸਾਡੇ ਨਾਲ ਚੈਟ ਕਰੋ',
            'sv' => 'Chatta med oss',
            'da' => 'Chat med os',
            'no' => 'Chat med oss',
            'fi' => 'Keskustele kanssamme',
            'cs' => 'Napište nám',
            'ro' => 'Discută cu noi',
            'hu' => 'Írjon nekünk',
            'ms' => 'Sembang dengan kami',
            'sw' => 'Zungumza nasi',
        );
        $code = strtolower( substr( (string) $code, 0, 2 ) );
        if ( isset( $titles[ $code ] ) ) {
            return $titles[ $code ];
        }
        $cached = self::cached_site_defaults( $code );
        return ( $cached && ! empty( $cached['title'] ) ) ? $cached['title'] : $titles['en'];
    }

    /**
     * The built-in input hint in the site's language where we have one, else
     * the cached translation, else English. Added languages are translated
     * from the English line, and the widget renders it when the owner set none.
     *
     * @param string $code Language code.
     */
    public static function default_placeholder( $code = 'en' ) {
        $lines = array(
            'en' => 'Type your message...',
            'fa' => 'پیام خود را بنویسید...',
            'ar' => 'اكتب رسالتك...',
            'he' => 'כתבו הודעה...',
            'ur' => 'اپنا پیغام لکھیں...',
            'hi' => 'अपना संदेश लिखें...',
            'es' => 'Escribe tu mensaje...',
            'fr' => 'Écrivez votre message...',
            'de' => 'Nachricht eingeben...',
            'pt' => 'Digite sua mensagem...',
            'it' => 'Scrivi il tuo messaggio...',
            'nl' => 'Typ uw bericht...',
            'pl' => 'Napisz wiadomość...',
            'tr' => 'Mesajınızı yazın...',
            'ru' => 'Введите сообщение...',
            'uk' => 'Введіть повідомлення...',
            'el' => 'Γράψτε το μήνυμά σας...',
            'zh' => '输入您的消息...',
            'ja' => 'メッセージを入力...',
            'ko' => '메시지를 입력하세요...',
            'id' => 'Ketik pesan Anda...',
            'vi' => 'Nhập tin nhắn của bạn...',
            'th' => 'พิมพ์ข้อความของคุณ...',
            'bn' => 'আপনার বার্তা লিখুন...',
            'ta' => 'உங்கள் செய்தியை எழுதுங்கள்...',
            'pa' => 'ਆਪਣਾ ਸੁਨੇਹਾ ਲਿਖੋ...',
            'sv' => 'Skriv ditt meddelande...',
            'da' => 'Skriv din besked...',
            'no' => 'Skriv meldingen din...',
            'fi' => 'Kirjoita viestisi...',
            'cs' => 'Napište zprávu...',
            'ro' => 'Scrieți mesajul...',
            'hu' => 'Írja be üzenetét...',
            'ms' => 'Taip mesej anda...',
            'sw' => 'Andika ujumbe wako...',
        );
        $code = strtolower( substr( (string) $code, 0, 2 ) );
        if ( isset( $lines[ $code ] ) ) {
            return $lines[ $code ];
        }
        $cached = self::cached_site_defaults( $code );
        return ( $cached && ! empty( $cached['placeholder'] ) ) ? $cached['placeholder'] : $lines['en'];
    }

    /** The pre-chat form's fixed lines, in the order gate_copy() builds them. */
    const GATE_KEYS = array( 'heading', 'pendingLabel', 'submit', 'submitting', 'back', 'networkError', 'genericError' );

    /**
     * The pre-chat form's own fixed text (heading, buttons, errors) in a language we have built-in text for,
     * else English. The form's field labels are the owner's and are not part of this.
     *
     * @param string $code Language code.
     * @return array<string,string> keyed by GATE_KEYS.
     */
    public static function gate_copy( $code = 'en' ) {
        $lines = array(
            'en' => array( 'A few details first', 'Your message, sent once you share your details', 'Continue', 'Sending...', 'Back', 'Network error. Please try again.', 'Something went wrong. Please try again.' ),
            'fa' => array( 'ابتدا چند مورد از اطلاعات شما', 'پیام شما پس از وارد کردن مشخصات ارسال می‌شود', 'ادامه', 'در حال ارسال...', 'بازگشت', 'خطای شبکه. لطفاً دوباره تلاش کنید.', 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.' ),
            'ar' => array( 'بعض التفاصيل أولاً', 'سنرسل رسالتك بعد إدخال بياناتك', 'متابعة', 'جارٍ الإرسال...', 'رجوع', 'خطأ في الشبكة. يُرجى المحاولة مرة أخرى.', 'حدث خطأ ما. يُرجى المحاولة مرة أخرى.' ),
            'he' => array( 'כמה פרטים קודם', 'ההודעה שלך תישלח לאחר מילוי הפרטים', 'המשך', 'שולח...', 'חזרה', 'שגיאת רשת. נסו שוב.', 'משהו השתבש. נסו שוב.' ),
            'ur' => array( 'پہلے چند تفصیلات', 'آپ کا پیغام تفصیلات دینے کے بعد بھیجا جائے گا', 'جاری رکھیں', 'بھیجا جا رہا ہے...', 'واپس', 'نیٹ ورک کی خرابی۔ براہ کرم دوبارہ کوشش کریں۔', 'کچھ غلط ہو گیا۔ براہ کرم دوبارہ کوشش کریں۔' ),
            'hi' => array( 'पहले कुछ जानकारी', 'आपका संदेश, जानकारी देने के बाद भेजा जाएगा', 'आगे बढ़ें', 'भेजा जा रहा है...', 'वापस', 'नेटवर्क त्रुटि। कृपया फिर से प्रयास करें।', 'कुछ गड़बड़ हो गई। कृपया फिर से प्रयास करें।' ),
            'es' => array( 'Primero, unos datos', 'Tu mensaje se enviará cuando compartas tus datos', 'Continuar', 'Enviando...', 'Atrás', 'Error de red. Inténtalo de nuevo.', 'Algo salió mal. Inténtalo de nuevo.' ),
            'fr' => array( 'Quelques informations d\'abord', 'Votre message sera envoyé une fois vos coordonnées saisies', 'Continuer', 'Envoi...', 'Retour', 'Erreur réseau. Veuillez réessayer.', 'Une erreur s\'est produite. Veuillez réessayer.' ),
            'de' => array( 'Zuerst ein paar Angaben', 'Ihre Nachricht wird gesendet, sobald Sie Ihre Angaben gemacht haben', 'Weiter', 'Wird gesendet...', 'Zurück', 'Netzwerkfehler. Bitte versuchen Sie es erneut.', 'Etwas ist schiefgelaufen. Bitte versuchen Sie es erneut.' ),
            'pt' => array( 'Primeiro, alguns dados', 'Sua mensagem será enviada depois que você informar seus dados', 'Continuar', 'Enviando...', 'Voltar', 'Erro de rede. Tente novamente.', 'Algo deu errado. Tente novamente.' ),
            'it' => array( 'Prima alcuni dati', 'Il tuo messaggio verrà inviato dopo aver inserito i tuoi dati', 'Continua', 'Invio...', 'Indietro', 'Errore di rete. Riprova.', 'Qualcosa è andato storto. Riprova.' ),
            'nl' => array( 'Eerst een paar gegevens', 'Je bericht wordt verzonden zodra je je gegevens hebt ingevuld', 'Doorgaan', 'Verzenden...', 'Terug', 'Netwerkfout. Probeer het opnieuw.', 'Er ging iets mis. Probeer het opnieuw.' ),
            'pl' => array( 'Najpierw kilka informacji', 'Twoja wiadomość zostanie wysłana po podaniu danych', 'Dalej', 'Wysyłanie...', 'Wstecz', 'Błąd sieci. Spróbuj ponownie.', 'Coś poszło nie tak. Spróbuj ponownie.' ),
            'tr' => array( 'Önce birkaç bilgi', 'Mesajınız, bilgilerinizi paylaştıktan sonra gönderilecek', 'Devam', 'Gönderiliyor...', 'Geri', 'Ağ hatası. Lütfen tekrar deneyin.', 'Bir sorun oluştu. Lütfen tekrar deneyin.' ),
            'ru' => array( 'Сначала немного информации', 'Ваше сообщение будет отправлено после ввода данных', 'Продолжить', 'Отправка...', 'Назад', 'Ошибка сети. Попробуйте ещё раз.', 'Что-то пошло не так. Попробуйте ещё раз.' ),
            'uk' => array( 'Спершу кілька даних', 'Ваше повідомлення буде надіслано після введення даних', 'Продовжити', 'Надсилання...', 'Назад', 'Помилка мережі. Спробуйте ще раз.', 'Щось пішло не так. Спробуйте ще раз.' ),
            'el' => array( 'Πρώτα μερικά στοιχεία', 'Το μήνυμά σας θα σταλεί μόλις συμπληρώσετε τα στοιχεία σας', 'Συνέχεια', 'Αποστολή...', 'Πίσω', 'Σφάλμα δικτύου. Δοκιμάστε ξανά.', 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.' ),
            'zh' => array( '请先填写几项信息', '填写信息后将发送您的消息', '继续', '正在发送...', '返回', '网络错误，请重试。', '出了点问题，请重试。' ),
            'ja' => array( 'まずいくつかご入力ください', 'ご入力後にメッセージを送信します', '続ける', '送信中...', '戻る', 'ネットワークエラーです。もう一度お試しください。', '問題が発生しました。もう一度お試しください。' ),
            'ko' => array( '먼저 몇 가지 정보를 입력해 주세요', '정보를 입력하시면 메시지가 전송됩니다', '계속', '보내는 중...', '뒤로', '네트워크 오류입니다. 다시 시도해 주세요.', '문제가 발생했습니다. 다시 시도해 주세요.' ),
            'id' => array( 'Beberapa detail dulu', 'Pesan Anda dikirim setelah Anda mengisi detail', 'Lanjutkan', 'Mengirim...', 'Kembali', 'Kesalahan jaringan. Silakan coba lagi.', 'Terjadi kesalahan. Silakan coba lagi.' ),
            'vi' => array( 'Trước tiên, một vài thông tin', 'Tin nhắn của bạn sẽ được gửi sau khi bạn điền thông tin', 'Tiếp tục', 'Đang gửi...', 'Quay lại', 'Lỗi mạng. Vui lòng thử lại.', 'Đã xảy ra lỗi. Vui lòng thử lại.' ),
            'th' => array( 'ขอรายละเอียดเล็กน้อยก่อน', 'ข้อความของคุณจะถูกส่งเมื่อคุณกรอกรายละเอียดแล้ว', 'ดำเนินการต่อ', 'กำลังส่ง...', 'ย้อนกลับ', 'เครือข่ายขัดข้อง โปรดลองอีกครั้ง', 'เกิดข้อผิดพลาด โปรดลองอีกครั้ง' ),
            'bn' => array( 'প্রথমে কিছু তথ্য', 'আপনার তথ্য দেওয়ার পর বার্তাটি পাঠানো হবে', 'এগিয়ে যান', 'পাঠানো হচ্ছে...', 'ফিরে যান', 'নেটওয়ার্ক ত্রুটি। আবার চেষ্টা করুন।', 'কিছু ভুল হয়েছে। আবার চেষ্টা করুন।' ),
            'ta' => array( 'முதலில் சில விவரங்கள்', 'உங்கள் விவரங்களை அளித்ததும் செய்தி அனுப்பப்படும்', 'தொடரவும்', 'அனுப்புகிறது...', 'பின்செல்', 'நெட்வொர்க் பிழை. மீண்டும் முயற்சிக்கவும்.', 'ஏதோ தவறு ஏற்பட்டது. மீண்டும் முயற்சிக்கவும்.' ),
            'pa' => array( 'ਪਹਿਲਾਂ ਕੁਝ ਵੇਰਵੇ', 'ਤੁਹਾਡਾ ਸੁਨੇਹਾ ਵੇਰਵੇ ਦੇਣ ਤੋਂ ਬਾਅਦ ਭੇਜਿਆ ਜਾਵੇਗਾ', 'ਜਾਰੀ ਰੱਖੋ', 'ਭੇਜਿਆ ਜਾ ਰਿਹਾ ਹੈ...', 'ਵਾਪਸ', 'ਨੈੱਟਵਰਕ ਗੜਬੜ। ਕਿਰਪਾ ਕਰਕੇ ਦੁਬਾਰਾ ਕੋਸ਼ਿਸ਼ ਕਰੋ।', 'ਕੁਝ ਗਲਤ ਹੋ ਗਿਆ। ਕਿਰਪਾ ਕਰਕੇ ਦੁਬਾਰਾ ਕੋਸ਼ਿਸ਼ ਕਰੋ।' ),
            'sv' => array( 'Först några uppgifter', 'Ditt meddelande skickas när du har fyllt i dina uppgifter', 'Fortsätt', 'Skickar...', 'Tillbaka', 'Nätverksfel. Försök igen.', 'Något gick fel. Försök igen.' ),
            'da' => array( 'Først et par oplysninger', 'Din besked sendes, når du har udfyldt dine oplysninger', 'Fortsæt', 'Sender...', 'Tilbage', 'Netværksfejl. Prøv igen.', 'Noget gik galt. Prøv igen.' ),
            'no' => array( 'Først noen opplysninger', 'Meldingen din sendes når du har fylt inn opplysningene', 'Fortsett', 'Sender...', 'Tilbake', 'Nettverksfeil. Prøv igjen.', 'Noe gikk galt. Prøv igjen.' ),
            'fi' => array( 'Ensin muutama tieto', 'Viestisi lähetetään, kun olet antanut tietosi', 'Jatka', 'Lähetetään...', 'Takaisin', 'Verkkovirhe. Yritä uudelleen.', 'Jokin meni vikaan. Yritä uudelleen.' ),
            'cs' => array( 'Nejprve pár údajů', 'Vaše zpráva se odešle, jakmile vyplníte své údaje', 'Pokračovat', 'Odesílání...', 'Zpět', 'Chyba sítě. Zkuste to prosím znovu.', 'Něco se pokazilo. Zkuste to prosím znovu.' ),
            'ro' => array( 'Mai întâi câteva detalii', 'Mesajul tău va fi trimis după ce completezi datele', 'Continuă', 'Se trimite...', 'Înapoi', 'Eroare de rețea. Încearcă din nou.', 'Ceva nu a mers bine. Încearcă din nou.' ),
            'hu' => array( 'Először néhány adat', 'Üzenetét az adatai megadása után küldjük el', 'Tovább', 'Küldés...', 'Vissza', 'Hálózati hiba. Kérjük, próbálja újra.', 'Hiba történt. Kérjük, próbálja újra.' ),
            'ms' => array( 'Beberapa butiran dahulu', 'Mesej anda dihantar selepas anda mengisi butiran', 'Teruskan', 'Menghantar...', 'Kembali', 'Ralat rangkaian. Sila cuba lagi.', 'Sesuatu tidak kena. Sila cuba lagi.' ),
            'sw' => array( 'Kwanza maelezo machache', 'Ujumbe wako utatumwa ukishajaza maelezo yako', 'Endelea', 'Inatuma...', 'Rudi', 'Hitilafu ya mtandao. Tafadhali jaribu tena.', 'Hitilafu imetokea. Tafadhali jaribu tena.' ),
        );
        $code = strtolower( substr( (string) $code, 0, 2 ) );
        return array_combine( self::GATE_KEYS, isset( $lines[ $code ] ) ? $lines[ $code ] : $lines['en'] );
    }

    /** gate_copy() for each code: what the widget config carries so the form follows the visitor's language. */
    public static function gate_copy_map( $codes ) {
        $map = array();
        foreach ( (array) $codes as $code ) {
            $code = strtolower( substr( (string) $code, 0, 2 ) );
            if ( '' !== $code ) {
                $map[ $code ] = self::gate_copy( $code );
            }
        }
        return $map;
    }

    /** Option holding the one machine translation of the English defaults into a site language we have no built-in text for. */
    const SITE_DEFAULTS_OPTION = 'bitbot_widget_site_defaults';

    /** The cached translated defaults for this code, or null. */
    public static function cached_site_defaults( $code ) {
        if ( ! function_exists( 'get_option' ) ) {
            return null;
        }
        $row = get_option( self::SITE_DEFAULTS_OPTION, array() );
        if ( ! is_array( $row ) || empty( $row['code'] ) || $row['code'] !== $code ) {
            return null;
        }
        return $row;
    }

    /** True when the built-in text exists for this code (no translation needed). */
    public static function has_builtin_defaults( $code ) {
        $code = strtolower( substr( (string) $code, 0, 2 ) );
        $titles = array( 'en' );
        return 'en' === $code || self::default_title( $code ) !== self::default_title( 'en' ) || in_array( $code, $titles, true );
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
            'fa' => 'سلام! امروز چطور می‌توانم به شما در استفاده از وب‌سایت کمک کنم؟',
            'he' => 'שלום! איך אוכל לעזור לכם למצוא את דרככם באתר היום?',
            'ur' => 'سلام! آج ویب سائٹ پر آپ کی کیا مدد ہو سکتی ہے؟',
            'it' => 'Ciao! Come posso aiutarti a navigare nel nostro sito oggi?',
            'nl' => 'Hallo! Hoe kan ik u vandaag helpen op onze website?',
            'pl' => 'Cześć! Jak mogę dziś pomóc w poruszaniu się po naszej stronie?',
            'tr' => 'Merhaba! Bugün web sitemizde size nasıl yardımcı olabilirim?',
            'uk' => 'Привіт! Чим можу допомогти вам на нашому сайті сьогодні?',
            'el' => 'Γεια σας! Πώς μπορώ να σας βοηθήσω στον ιστότοπό μας σήμερα;',
            'id' => 'Halo! Ada yang bisa saya bantu di situs kami hari ini?',
            'vi' => 'Xin chào! Hôm nay tôi có thể giúp gì cho bạn trên trang web của chúng tôi?',
            'th' => 'สวัสดี! วันนี้มีอะไรให้ช่วยบนเว็บไซต์ของเราไหม?',
            'bn' => 'নমস্কার! আজ আমাদের ওয়েবসাইটে আপনাকে কীভাবে সাহায্য করতে পারি?',
            'ta' => 'வணக்கம்! இன்று எங்கள் இணையதளத்தில் உங்களுக்கு எப்படி உதவலாம்?',
            'pa' => 'ਸਤਿ ਸ੍ਰੀ ਅਕਾਲ! ਅੱਜ ਸਾਡੀ ਵੈੱਬਸਾਈਟ \'ਤੇ ਤੁਹਾਡੀ ਕੀ ਮਦਦ ਹੋ ਸਕਦੀ ਹੈ?',
            'sv' => 'Hej! Hur kan jag hjälpa dig på vår webbplats idag?',
            'da' => 'Hej! Hvordan kan jeg hjælpe dig på vores hjemmeside i dag?',
            'no' => 'Hei! Hvordan kan jeg hjelpe deg på nettstedet vårt i dag?',
            'fi' => 'Hei! Miten voin auttaa sinua sivustollamme tänään?',
            'cs' => 'Dobrý den! Jak vám dnes mohu pomoci na našem webu?',
            'ro' => 'Bună! Cu ce vă pot ajuta pe site-ul nostru astăzi?',
            'hu' => 'Üdvözlöm! Miben segíthetek ma a weboldalunkon?',
            'ms' => 'Hai! Bagaimana saya boleh membantu anda di laman web kami hari ini?',
            'sw' => 'Habari! Nikusaidie vipi kwenye tovuti yetu leo?',
        );
        $code = strtolower( substr( (string) $code, 0, 2 ) );
        if ( isset( $greetings[ $code ] ) ) {
            return $greetings[ $code ];
        }
        $cached = self::cached_site_defaults( $code );
        return ( $cached && ! empty( $cached['welcome'] ) ) ? $cached['welcome'] : $greetings['en'];
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
                ! empty( $row['failed'] ),
                isset( $row['rules'] ) ? (int) $row['rules'] : 0
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
            if ( (int) $row['rules'] !== self::AUTO_RULES ) {
                // Made under older translation rules: remade.
                $out[] = $code;
                continue;
            }
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
     * Auto rows in the selection made under older translation rules (owner
     * rows and the site language never). What a settings read remakes.
     *
     * @param array  $selection Effective codes.
     * @param string $site_code Site language.
     * @param array  $stored    Normalized rows.
     */
    public static function stale_auto_codes( $selection, $site_code, $stored ) {
        $out = array();
        foreach ( (array) $selection as $code ) {
            if ( ! is_string( $code ) || $code === $site_code || in_array( $code, $out, true ) || ! isset( $stored[ $code ] ) ) {
                continue;
            }
            if ( ! empty( $stored[ $code ]['auto'] ) && (int) $stored[ $code ]['rules'] !== self::AUTO_RULES ) {
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
     * merge_auto as ONE compare-and-swap: read the stored rows, merge, write
     * only if the stored value is still what was read; otherwise read again.
     * An owner save that lands between the read and the write is never lost.
     *
     * @param callable $read     () => raw stored option value (null when absent), no cache.
     * @param callable $write_if ( $expected_raw, array $rows ) => bool, true when written.
     * @param array    $results  code => array( title, welcome, ... ) from the API.
     * @param int      $attempts How many times a lost race is retried.
     * @return array|null The rows written, or null when every attempt lost the race.
     */
    public static function cas_merge( $read, $write_if, $results, $attempts = 3 ) {
        for ( $i = 0; $i < max( 1, (int) $attempts ); $i++ ) {
            $raw  = call_user_func( $read );
            $rows = self::merge_auto( self::normalize( $raw ), $results );
            if ( call_user_func( $write_if, $raw, $rows ) ) {
                return $rows;
            }
        }
        return null;
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
            $stored[ $code ] = self::row( $title, $welcome, $questions, $placeholder, true, false, false, self::AUTO_RULES );
        }
        return $stored;
    }

    /** A complete row. */
    private static function row( $title, $welcome, $questions, $placeholder, $auto, $needs_review, $failed, $rules = 0 ) {
        return array(
            'title'        => $title,
            'welcome'      => $welcome,
            'questions'    => $questions,
            'placeholder'  => $placeholder,
            'auto'         => (bool) $auto,
            'needs_review' => (bool) $needs_review,
            'failed'       => (bool) $failed,
            // Translation rules the row was made under (auto rows); 0 = unknown or an owner row.
            'rules'        => (int) $rules,
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
            $stored[ $code ] = self::row( $title, $welcome, $questions, $placeholder, true, false, false, self::AUTO_RULES );
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
