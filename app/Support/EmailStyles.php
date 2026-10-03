<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EmailStyles
//  Location: app/Support/EmailStyles.php
//
//  The look of every email, as inline style strings.
//
//  INLINE is not a preference here. The templates used to keep their
//  whole design in a <style> block in <head>, and a good number of
//  mail clients delete that block on delivery. When one does, the
//  entire design goes with it — no card, no header band, no code
//  box, no footer — and what lands is unstyled text running the full
//  width of the reading pane.
//
//  What makes that hard to recognise is how it presents. It does not
//  look like "the styles were dropped". It looks like ONE element is
//  in the wrong place, because the only styles that survive are the
//  inline ones, and the templates had exactly two — both a
//  `text-align: center` on an expiry line. That line was the only
//  thing still obeying its styling, so it read as the single broken
//  element in an otherwise fine email. It was the opposite: the only
//  unbroken one.
//
//  Shared into every `emails.*` view by AppServiceProvider, so the
//  layout and the content templates draw from the same map — Blade
//  renders a @section BEFORE the layout that wraps it, so a variable
//  defined in the layout is not available to the section, and the
//  two would otherwise have to keep their own copies.
// ══════════════════════════════════════════════════════════════════
final class EmailStyles
{
    private const NAVY  = '#0C1829';   // El Tara deep navy (--bg)
    private const GREEN = '#1E7A5C';   // El Tara green, light theme (--gn)
    private const BODY  = '#4A5568';
    private const MUTED = '#8A96A8';

    // The brand mark on the navy header uses the app's dark-theme colours,
    // as the app's own .brand-mark does on a dark background.
    private const MARK_FROM  = '#3E82D6';   // --ms-navy, dark theme
    private const MARK_TO    = '#059767';   // --ms-green, dark theme
    private const MARK_SOLID = '#218C9E';   // between the two, for clients without gradients (Outlook)

    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        $rtl   = $locale === 'ar';
        $align = $rtl ? 'right' : 'left';
        // The app's fonts (app.blade.php): Inter, and Tajawal for Arabic.
        $font  = $rtl
            ? "'Tajawal', 'Segoe UI', Tahoma, sans-serif"
            : "'Inter', 'Segoe UI', Helvetica, Arial, sans-serif";
        $brandFont = "'Inter', 'Tajawal', 'Segoe UI', Helvetica, Arial, sans-serif";

        $text = "font-family:{$font}; font-size:15px; line-height:1.6;";

        return [
            'dir'   => $rtl ? 'rtl' : 'ltr',
            'align' => $align,
            'font'  => $font,

            // The inbox preview line (hidden in the email itself).
            'preheader' => 'display:none; font-size:1px; line-height:1px; max-height:0; max-width:0; opacity:0; overflow:hidden; mso-hide:all; color:#F4F6F9;',

            'wrapper'   => 'width:100%; background-color:#F4F6F9; padding:32px 16px;',
            'container' => 'max-width:560px; margin:0 auto; background:#FFFFFF; border-radius:14px; overflow:hidden; border:1px solid #DDE2EB;',
            'header'    => 'background:'.self::NAVY.'; padding:24px 32px; text-align:center;',

            // The app's own brand mark (app.css .brand-mark: a white M on
            // the brand gradient), drawn in HTML rather than an image.
            // Most mail clients block remote images by default, and an
            // <img> also needs a public address to load from; text and
            // colour always arrive. Outlook for Windows shows it square
            // and in one colour (MARK_SOLID).
            'markTable' => 'margin:0 auto 10px; border-collapse:separate;',
            'mark'      => 'width:44px; height:44px; border-radius:11px; background-color:'.self::MARK_SOLID.'; '
                .'background-image:linear-gradient(135deg, '.self::MARK_FROM.', '.self::MARK_TO.'); '
                ."color:#FFFFFF; font-family:{$brandFont}; font-size:22px; font-weight:800; line-height:44px; text-align:center; mso-line-height-rule:exactly;",

            // "El Tara التارة", as in the app's top bar (.brand-name).
            'brand'     => "font-family:{$brandFont}; font-size:18px; font-weight:800; color:#FFFFFF; letter-spacing:0.02em; margin:0; text-align:center;",
            'brandAlt'  => 'color:'.self::MARK_TO.';',
            'accent'    => 'height:4px; line-height:4px; font-size:0; background:'.self::GREEN.';',
            'body'      => "padding:32px; {$text} color:".self::NAVY."; text-align:{$align};",

            'h1'    => "margin:0 0 12px; font-family:{$font}; font-size:20px; font-weight:700; color:".self::NAVY."; letter-spacing:-0.02em; text-align:{$align};",
            'p'     => "margin:0 0 16px; {$text} color:".self::BODY."; text-align:{$align};",
            'muted' => "margin:0 0 16px; font-family:{$font}; font-size:13px; line-height:1.6; color:".self::MUTED."; text-align:{$align};",

            // The caption belongs to the box above it, so it centres
            // with the box rather than aligning to the body text.
            'caption' => "margin:8px 0 16px; font-family:{$font}; font-size:13px; line-height:1.6; color:".self::MUTED.'; text-align:center;',

            'codeBox' => 'margin:24px 0; padding:20px; background:#E8F5EF; border:2px dashed '.self::GREEN.'; border-radius:12px; text-align:center;',
            'code'    => "font-family:'Courier New', Courier, monospace; font-size:32px; font-weight:800; letter-spacing:0.35em; color:".self::NAVY.';',
            'date'    => "font-family:'Courier New', Courier, monospace; font-size:24px; font-weight:800; letter-spacing:0.05em; color:".self::NAVY.';',

            'btnWrap' => 'margin:28px 0; text-align:center;',
            'btn'     => "display:inline-block; background:".self::GREEN."; color:#FFFFFF; text-decoration:none; font-family:{$font}; font-weight:600; font-size:15px; padding:14px 32px; border-radius:10px;",
            'link'    => "margin:0 0 16px; font-family:{$font}; font-size:13px; color:".self::GREEN."; word-break:break-all; text-align:{$align};",

            'footer'  => 'padding:20px 32px 28px; text-align:center; border-top:1px solid #EEF1F6; background:#FAFBFC;',
            'footerP' => "margin:0; font-family:{$font}; font-size:12px; color:".self::MUTED.'; text-align:center;',
        ];
    }
}
