<?php
/** Cuerpo de /blog/amazon-relay-requirements/ (PostRelayRequirements.html). Ver post.php para el contrato. */
$hl = 'requirements';
$takeaways = [
    'Amazon sets the Relay requirements and updates them, so the official list on relay.amazon.com is the only one to trust.',
    'Expect Amazon to look at your carrier authority, your insurance and your safety record.',
    'Keep your company details consistent everywhere you register.',
    'Once you are in, your scorecard matters: it follows the loads you book and how you run them.',
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
?>
<h2 class="t-prose-h2" id="who">Who can sign up</h2>
<p>Amazon Relay is a program for motor carriers, and Amazon decides who qualifies. The rules change over time, so we do not list them here. Read the current requirements on <strong>relay.amazon.com</strong> before you apply, and check them again when you renew anything.</p>
<p>In general, expect Amazon to ask for your carrier details, such as your USDOT and MC numbers, and to compare them with your FMCSA records. If you are not sure you qualify, ask Amazon directly or message the desk.</p>

<h2 class="t-prose-h2" id="insurance">Insurance</h2>
<p>Relay checks your coverage before approval and keeps checking it. The types of coverage and the minimum amounts are set by Amazon and can change, so we do not quote any numbers. Find the current insurance requirements on <strong>relay.amazon.com</strong> and send them to your insurance agent before you ask for a quote.</p>
<p>Keep your certificates current and renew before the expiry date, so your account does not get paused.</p>
<?php $callout(); ?>

<h2 class="t-prose-h2" id="equipment">Equipment and technology</h2>
<p>The equipment and technology Amazon asks for depends on the type of load and can change. Check the load details inside Relay and the requirements on <strong>relay.amazon.com</strong>, and make sure your trucks and drivers can meet them before you book.</p>
<ul>
  <li>Check what each load needs before you accept it.</li>
  <li>Make sure your drivers have what the program asks for, including the Relay app on their phones for check-ins and tracking.</li>
</ul>

<h2 class="t-prose-h2" id="approval">The approval steps</h2>
<p>Registration is done online through Amazon, which reviews your information and may ask for documents. Once you are approved, you get access to the load board and Amazon starts measuring your performance on a scorecard.</p>
<p>Keep your FMCSA data, insurance and company details consistent: mismatches are a common reason for delays on any carrier registration.</p>

<h2 class="t-prose-h2" id="after">After approval: keep the account healthy</h2>
<p>Relay tracks how you perform on the loads you book. A healthy scorecard keeps more freight available to you. That is where a dispatch desk helps: booking loads that fit your drivers' hours, keeping the app active and documenting every incident. <a href="<?= e(url('/amazon-relay-dispatch/')) ?>">See our Amazon Relay dispatch service</a>.</p>
