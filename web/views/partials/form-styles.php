<?php
/**
 * Shared Tailwind class strings for form controls.
 * require'd (not $this->include'd) by the form pages so these variables land in
 * the caller's scope. Keeps field styling consistent in one place.
 */
$labelCls   = 'block text-sm font-semibold text-navy mb-1.5';
$inputCls   = 'w-full rounded-lg border border-navy/15 bg-white px-4 py-2.5 text-sm text-ink placeholder:text-ink/35 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20 transition';
$hintCls    = 'mt-1.5 text-xs text-ink/50';
$cardCls    = 'rounded-card border border-navy/10 bg-white p-6 sm:p-8';
$sectionCls = 'text-lg font-bold text-navy';
$checkCls   = 'h-4 w-4 rounded border-navy/25 text-brand focus:ring-brand/30';
$radioCls   = 'h-4 w-4 border-navy/25 text-brand focus:ring-brand/30';
$chipCls    = 'flex cursor-pointer items-center gap-2 rounded-full border border-navy/15 px-4 py-2 text-sm text-ink hover:border-brand transition has-[:checked]:border-brand has-[:checked]:bg-cream-50 has-[:checked]:text-navy has-[:checked]:font-semibold';
$req        = '<span class="text-brand" aria-hidden="true">*</span>';
