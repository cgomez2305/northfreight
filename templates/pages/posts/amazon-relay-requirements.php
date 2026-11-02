<?php
/** Cuerpo de /blog/amazon-relay-requirements/ (PostRelayRequirements.html). Ver post.php para el contrato. */
$hl = 'requirements';
$takeaways = [
    'Relay works with carriers that have their own active USDOT and MC authority.',
    'You need a SCAC code and insurance that meets Relay\'s current minimums.',
    'Drivers use the Relay app for tracking on every load.',
    'Requirements change, so confirm the current list on relay.amazon.com before applying.',
];
$toc = [
    ['who', 'Who can sign up'],
    ['insurance', 'Insurance'],
    ['equipment', 'Equipment and technology'],
    ['approval', 'The approval steps'],
    ['after', 'After approval: keep the account healthy'],
];
$related = [
    ['code' => 'ARTICLE', 'title' => 'How the Relay load board works', 'text' => 'One-ways, round trips and blocks.', 'href' => '/blog/amazon-relay-load-board/'],
    ['code' => 'GUIDE', 'title' => 'Trucking glossary', 'text' => 'SCAC, power only and the rest of the terms.', 'href' => '/resources/glossary/'],
    ['code' => 'SERVICE', 'title' => 'Amazon Relay dispatch', 'text' => 'The desk books and protects your Relay account.', 'href' => '/amazon-relay-dispatch/', 'dark' => true],
];
$limits = ph('Current minimum limits: verify on relay.amazon.com before publishing.');
?>
<h2 class="t-prose-h2" id="who">Who can sign up</h2>
<p>Amazon Relay is for motor carriers operating under their own authority. Brokers and drivers leased on to another carrier cannot book Relay freight on their own.</p>
<ul>
  <li>An active <strong>USDOT number</strong> and <strong>MC number</strong> in good standing with the FMCSA</li>
  <li>A <strong>SCAC code</strong> (Standard Carrier Alpha Code) registered to your company</li>
  <li>A business email, tax information and company details that match your FMCSA records</li>
</ul>

<h2 class="t-prose-h2" id="insurance">Insurance</h2>
<p>Relay checks your coverage before approval and keeps checking it. You will need at least auto liability and cargo coverage, with the minimum amounts set by Amazon.</p>
<p><?php if ($limits !== ''): ?><strong><?= $limits ?></strong> <?php endif; ?>Make sure your certificate lists Amazon as required and that renewals are sent before expiry, or your account can be paused.</p>
<?php $callout(); ?>

<h2 class="t-prose-h2" id="equipment">Equipment and technology</h2>
<ul>
  <li><strong>Tractors</strong> able to pull 53' trailers, since many Relay loads are power-only with Amazon trailers</li>
  <li><strong>Your own 53' dry vans</strong> for loads that require carrier trailers</li>
  <li><strong>ELD-compliant</strong> trucks and drivers with valid CDLs</li>
  <li><strong>The Amazon Relay app</strong> on each driver's phone for check-ins and tracking</li>
</ul>

<h2 class="t-prose-h2" id="approval">The approval steps</h2>
<p>Registration is done online. Relay verifies your authority, insurance and safety record, and may ask for documents. Once approved, you get access to the load board and your performance starts being measured on a scorecard.</p>
<p>Keep your FMCSA data, insurance and SCAC information consistent: mismatches are the most common reason for delays.</p>

<h2 class="t-prose-h2" id="after">After approval: keep the account healthy</h2>
<p>Relay tracks on-time performance, cancellations and tracking compliance. A healthy scorecard keeps more freight available to you. That is where a dispatch desk helps: booking loads that fit your drivers' hours, keeping the app active and documenting every incident. <a href="<?= e(url('/amazon-relay-dispatch/')) ?>">See our Amazon Relay dispatch service</a>.</p>
