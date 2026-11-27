<?php

namespace App\Support\Csp;

use Spatie\Csp\Directive;
use Spatie\Csp\Keyword;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;

/**
 * The Content Security Policy of the admin interface.
 *
 * Scripts only run from this site or with the nonce of the request, and never through eval. Inline styles are
 * allowed because Flux and Livewire set inline style attributes, and style injection is a low risk compared to script
 * injection. The interface loads nothing from a third party, so everything else is limited to this site.
 */
class VaultPolicy implements Preset
{
    public function configure(Policy $policy): void
    {
        $policy
            ->add(Directive::DEFAULT, Keyword::SELF)
            ->add(Directive::SCRIPT, Keyword::SELF)
            ->addNonce(Directive::SCRIPT)
            ->add(Directive::STYLE, [Keyword::SELF, Keyword::UNSAFE_INLINE])
            ->add(Directive::IMG, [Keyword::SELF, 'data:'])
            ->add(Directive::FONT, Keyword::SELF)
            ->add(Directive::CONNECT, Keyword::SELF)
            ->add(Directive::FORM_ACTION, Keyword::SELF)
            ->add(Directive::FRAME_ANCESTORS, Keyword::NONE)
            ->add(Directive::BASE, Keyword::NONE)
            ->add(Directive::OBJECT, Keyword::NONE);
    }
}
