<?php
/* TimeWalk Japan: shared visual system for Stories, Neighborhood Histories and Self-Guided Walks cards. */
if (!defined('ABSPATH')) {
    exit;
}

final class TWJ_Shared_Card_System_Module {
    const VERSION = '1.0.0';

    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'styles'), 195);
    }

    public function styles() {
        $css = '
:root{--twj-directory-card-gap:18px;--twj-directory-card-radius:12px;--twj-directory-card-border:#e2e7ed;--twj-directory-card-shadow:0 6px 18px rgba(23,32,51,.06);--twj-directory-card-title-size:1rem;--twj-directory-card-text-size:.84rem}
.twj-story-directory-grid,.twj-guide-grid,.twj-nh-grid{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:var(--twj-directory-card-gap)!important;align-items:stretch!important}
.twj-story-directory-card,.twj-guide-card,.twj-nh-card{overflow:hidden!important;margin:0!important;border:1px solid var(--twj-directory-card-border)!important;border-radius:var(--twj-directory-card-radius)!important;background:#fff!important;box-shadow:var(--twj-directory-card-shadow)!important}
.twj-story-directory-card__link,.twj-guide-card__link,.twj-nh-card__link{display:flex!important;height:100%!important;flex-direction:column!important;color:inherit!important;text-decoration:none!important}
.twj-story-directory-card__image,.twj-guide-card__visual,.twj-nh-card__image{display:block!important;aspect-ratio:16/9!important;overflow:hidden!important}
.twj-story-directory-card__image img,.twj-nh-card__image img{display:block!important;width:100%!important;height:100%!important;max-width:none!important;object-fit:cover!important}
.twj-story-directory-card__body,.twj-guide-card__body,.twj-nh-card__body{display:flex!important;flex:1!important;flex-direction:column!important;padding:14px!important}
.twj-story-directory-card__title,.twj-guide-card h2,.twj-nh-card__title{font-size:var(--twj-directory-card-title-size)!important;line-height:1.3!important}
.twj-story-directory-card__excerpt,.twj-guide-card__body>p:last-child,.twj-nh-card__description{font-size:var(--twj-directory-card-text-size)!important;line-height:1.5!important}
.twj-story-directory-card__link:focus,.twj-guide-card__link:focus,.twj-nh-card__link:focus{outline:3px solid #5aa5c3!important;outline-offset:3px!important}
@media(max-width:900px){.twj-story-directory-grid,.twj-guide-grid,.twj-nh-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}}
@media(max-width:600px){:root{--twj-directory-card-gap:9px;--twj-directory-card-title-size:.8rem;--twj-directory-card-text-size:.7rem}.twj-story-directory-grid,.twj-guide-grid,.twj-nh-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.twj-story-directory-card,.twj-guide-card,.twj-nh-card{border-radius:9px!important}.twj-story-directory-card__body,.twj-guide-card__body,.twj-nh-card__body{padding:8px!important}.twj-story-directory-card__excerpt,.twj-guide-card__body>p:last-child,.twj-nh-card__description{display:-webkit-box!important;-webkit-box-orient:vertical!important;-webkit-line-clamp:5!important;overflow:hidden!important}}
';
        wp_register_style('twj-shared-card-system', false, array(), self::VERSION);
        wp_enqueue_style('twj-shared-card-system');
        wp_add_inline_style('twj-shared-card-system', $css);
    }
}

new TWJ_Shared_Card_System_Module();
