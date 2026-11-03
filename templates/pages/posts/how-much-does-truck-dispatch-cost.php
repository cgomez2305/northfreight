<?php
/** Cuerpo de /blog/how-much-does-truck-dispatch-cost/ (PostDispatchCost.html). Ver post.php para el contrato. */
$hl = 'truck dispatch';
$takeaways = [
    'Percentage of the load gross is the most common model for <a href="' . e(url('/dispatch-for-owner-operators/')) . '">owner-operators</a>.',
    'Flat weekly fees are predictable but can cost more in slow weeks.',
    'Always check what the fee is calculated on and what services it includes.',
    'You should keep your authority and the right to refuse any load.',
];
$toc = [
    ['models', 'The three ways dispatchers charge'],
    ['math', 'What the math looks like'],
    ['included', 'What the fee should include'],
    ['flags', 'Red flags before you sign'],
    ['northstar', 'How NorthStar charges'],
];
$related = [
    ['code' => 'TOOL', 'title' => 'Rate per mile calculator', 'text' => 'See what a load leaves after fuel and fees.', 'href' => '/resources/rate-per-mile-calculator/'],
    ['code' => 'TEMPLATE', 'title' => 'Dispatcher-carrier agreement', 'text' => 'What a fair contract covers.', 'href' => '/resources/dispatcher-carrier-agreement/'],
    ['code' => 'SERVICE', 'title' => 'Dispatch for owner-operators', 'text' => 'Let the desk run your loads.', 'href' => '/dispatch-for-owner-operators/', 'dark' => true],
];
$pricing = ph('NorthStar pricing model: percentage, flat fee or plan details to be confirmed by the client.');
?>
<h2 class="t-prose-h2" id="models">The three ways dispatchers charge</h2>
<p><strong>Percentage of the gross.</strong> The dispatcher takes a share of what each load pays. It is the most common model for owner-operators because the fee moves with your revenue: a slow week costs less. Rates for this model are commonly quoted somewhere between 5% and 10%, depending on what is included.</p>
<p><strong>Flat weekly fee.</strong> A fixed amount per truck per week, whatever you haul. It is easy to budget, but you pay the same in a week with two short loads as in a week with five long ones.</p>
<p><strong>Per-load fee.</strong> A fixed amount for every load booked. Simple, but it can push a dispatcher toward many short loads instead of the best-paying lanes.</p>

<h2 class="t-prose-h2" id="math">What the math looks like</h2>
<p>Use the same numbers to compare offers. A worked example with a percentage model:</p>
<div class="blog-stats">
  <p class="blog-stat"><span class="mono blog-stat__k">LOAD GROSS</span><span class="head blog-stat__v">$2,500</span></p>
  <p class="blog-stat"><span class="mono blog-stat__k">8% FEE</span><span class="head blog-stat__v blog-stat__v--hl">$200</span></p>
  <p class="blog-stat"><span class="mono blog-stat__k">4 LOADS / WEEK</span><span class="head blog-stat__v">$800</span></p>
</div>
<p>Then put that fee next to what the dispatcher actually does for you: hours of load-board searching, broker calls, rate negotiation, check calls and paperwork. Our <a href="<?= e(url('/resources/rate-per-mile-calculator/')) ?>">rate per mile calculator</a> helps you see what each load leaves after fuel and fees.</p>
<?php $callout(); ?>

<h2 class="t-prose-h2" id="included">What the fee should include</h2>
<p>A fair dispatch fee usually covers the daily work of keeping your truck loaded:</p>
<ul>
  <li>Load search and booking on boards and with direct brokers</li>
  <li>Rate negotiation and reviewing the rate confirmation</li>
  <li>Broker setup packets with your authority, insurance and W-9</li>
  <li>Check calls, ETA updates and incident follow-up</li>
  <li>Collecting BOLs and PODs so you can invoice</li>
</ul>
<p>Some services charge separately for invoicing, factoring setup, IFTA or back-office work. Ask for the list in writing.</p>

<h2 class="t-prose-h2" id="flags">Red flags before you sign</h2>
<ul>
  <li><strong>Forced dispatch.</strong> You should always be able to refuse a load.</li>
  <li><strong>Control of your authority.</strong> The dispatcher works for you; your MC stays yours.</li>
  <li><strong>Fees on everything.</strong> Check whether the percentage applies to line haul only or to the full gross.</li>
  <li><strong>Long contracts and exit penalties.</strong> Good service keeps clients without lock-ins.</li>
</ul>
<p>Our free <a href="<?= e(url('/resources/dispatcher-carrier-agreement/')) ?>">dispatcher-carrier agreement template</a> shows what a fair contract covers.</p>

<h2 class="t-prose-h2" id="northstar">How NorthStar charges</h2>
<?php if ($pricing !== ''): ?><p><?= $pricing ?></p><?php endif; ?>
<p>Whatever the model, the fee covers booking, tracking and documenting every load, with updates on WhatsApp. <a href="<?= e(url('/how-it-works/')) ?>">See how truck dispatching works with us</a>, or explore our <a href="<?= e(url('/dispatch-for-owner-operators/')) ?>">dispatch services for owner-operators</a> and the rest of our <a href="<?= e(url('/')) ?>">truck dispatch services</a>.</p>
