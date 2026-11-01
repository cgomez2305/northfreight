<?php
/** Cuerpo de /blog/amazon-relay-load-board/ (PostRelayLoadBoard.html). Ver post.php para el contrato. */
$hl = 'load board';
$takeaways = [
    'Relay posts loads with the rate already set — no negotiation on most loads.',
    'You will see one-way loads, round trips and longer multi-stop work.',
    'Booking is fast, so good loads go quickly.',
    'Every booked load counts on your scorecard — cancellations hurt.',
];
$toc = [
    ['types', 'What you will find on the board'],
    ['search', 'Searching and filtering'],
    ['booking', 'Booking and assigning a driver'],
    ['scorecard', 'Why the scorecard matters'],
    ['desk', 'Where a dispatcher helps'],
];
$faq = [
    ['Can I negotiate Amazon Relay rates?', 'Most loads are booked at the posted rate. Some freight is offered at different prices over time, which is why monitoring the board matters.'],
    ['What happens if I cancel a Relay load?', 'Cancellations count against your scorecard, especially close to pickup. Book only loads you can cover.'],
    ['Do I need the Relay app?', 'Yes. Drivers use it for check-ins and tracking on every load.'],
];
$related = [
    ['code' => 'ARTICLE', 'title' => 'Amazon Relay requirements', 'text' => 'Authority, insurance and approval.', 'href' => '/blog/amazon-relay-requirements/'],
    ['code' => 'SERVICE', 'title' => 'Load tracking', 'text' => 'Every trip followed, every incident logged.', 'href' => '/load-tracking/'],
    ['code' => 'SERVICE', 'title' => 'Amazon Relay dispatch', 'text' => 'Let the desk watch the board for you.', 'href' => '/amazon-relay-dispatch/', 'dark' => true],
];
?>
<h2 class="t-prose-h2" id="types">What you will find on the board</h2>
<ul>
  <li><strong>One-way loads</strong> — a single trip from an Amazon facility to another location</li>
  <li><strong>Round trips</strong> — out and back, often returning the truck close to where it started</li>
  <li><strong>Multi-stop and longer work</strong> — several stops or a sequence of trips planned together</li>
</ul>
<p>Each listing shows the facilities, the times, the equipment and the payout, so you can decide in seconds.</p>

<h2 class="t-prose-h2" id="search">Searching and filtering</h2>
<p>You can filter by origin, destination, dates, equipment and trip length. Experienced Relay carriers save searches for their lanes and check the board often, because posted loads are booked quickly.</p>
<?php $callout(); ?>

<h2 class="t-prose-h2" id="booking">Booking and assigning a driver</h2>
<p>When you book, the load is yours at the posted rate. You then assign a driver and truck in Relay. The driver uses the Relay app to check in at the facility, follow the trip and confirm each stop.</p>

<h2 class="t-prose-h2" id="scorecard">Why the scorecard matters</h2>
<p>Relay measures on-time arrivals, tracking and cancellations. Booking a load your driver cannot cover, or letting tracking drop, shows up on the scorecard. Book only what fits your drivers' hours and keep everyone on the app.</p>

<h2 class="t-prose-h2" id="desk">Where a dispatcher helps</h2>
<p>A dispatch desk watches the board during your hours, books loads that fit your lanes, checks hours of service before booking, assigns drivers and follows every trip in the app. <a href="<?= e(url('/amazon-relay-dispatch/')) ?>">See how NorthStar dispatches Amazon Relay</a>.</p>
