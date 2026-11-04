<?php
/** Cuerpo de /blog/lumper-fees-detention-pay/ (PostLumperDetention.html). Ver post.php para el contrato. */
$hl = 'how to get paid';
$takeaways = [
    'Get approval from the broker before paying a lumper, and keep the receipt.',
    'Detention starts after the free time on your rate confirmation.',
    'Signed in and out times are your strongest evidence.',
    'Tell the broker when free time ends, not after the load.',
];
$toc = [
    ['lumper', 'What a lumper fee is'],
    ['lumper-paid', 'How to get the lumper reimbursed'],
    ['detention', 'How detention works'],
    ['proof', 'The evidence that gets detention paid'],
    ['settlement', 'Putting it on the settlement'],
];
$related = [
    ['code' => 'TEMPLATE', 'title' => 'Bill of lading template', 'text' => 'Signed times ready for detention proof.', 'href' => '/resources/bol-template/'],
    ['code' => 'GUIDE', 'title' => 'Trucking glossary', 'text' => 'Lumper, TONU, accessorials and more.', 'href' => '/resources/glossary/'],
    ['code' => 'SERVICE', 'title' => 'Trucking back office', 'text' => 'Claims followed with evidence.', 'href' => '/trucking-back-office/', 'dark' => true],
];
?>
<h2 class="t-prose-h2" id="lumper">What a lumper fee is</h2>
<p>A lumper is a third-party crew that unloads (or loads) the trailer at the facility. The fee is usually paid at the dock, by the driver or through a payment code from the broker, and then reimbursed.</p>

<h2 class="t-prose-h2" id="lumper-paid">How to get the lumper reimbursed</h2>
<ul>
  <li>Check the rate confirmation: does it mention lumper reimbursement?</li>
  <li>Call or message the broker <strong>before</strong> paying and get the approval in writing</li>
  <li>Keep the original lumper receipt with the facility name, amount and date</li>
  <li>Send the receipt with the POD so it goes on the same invoice</li>
</ul>
<?php $callout(); ?>

<h2 class="t-prose-h2" id="detention">How detention works</h2>
<p>Detention is pay for the time your truck waits beyond the free time, which is the hours included in the rate before detention starts. Free time and the hourly rate are set in the rate confirmation, so read them before you book.</p>

<h2 class="t-prose-h2" id="proof">The evidence that gets detention paid</h2>
<ul>
  <li>Arrival time, check-in time and departure time, all written and signed on the BOL</li>
  <li>A message to the broker when free time is about to end</li>
  <li>Photos of the BOL and, if needed, of the dock or the line</li>
</ul>
<p>Our desk logs these times on every load with timestamps and photos. That record is what turns waiting hours into a claim that gets paid. <a href="<?= e(url('/load-tracking/')) ?>">See how we track loads</a>.</p>

<h2 class="t-prose-h2" id="settlement">Putting it on the settlement</h2>
<p>Send lumper receipts and detention records with the POD, not weeks later. Late paperwork is the easiest claim for a broker to deny. A <a href="<?= e(url('/trucking-back-office/')) ?>">trucking back office</a> that files everything per load keeps these claims from slipping.</p>
<?php partial('minicalc', ['id' => 'lumper-rpm', 'title' => 'Try it: what does a load leave per mile?']); ?>
