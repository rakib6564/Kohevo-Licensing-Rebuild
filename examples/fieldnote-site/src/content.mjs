// All the words on the Fieldnote site. Sample copy for a fictional studio: replace it with your own.
export const site = {
  name: 'Fieldnote',
  tagline: 'An independent design and web studio',
  email: 'hello@fieldnote.example',
  city: 'Antwerp, Belgium',
  lat: 51.2194,
  lon: 4.4025,
  openTarget: '2027-01-12T08:00:00Z',
};

export const nav = [['Work', '/work'], ['Services', '/services'], ['About', '/about'], ['Contact', '/contact']];

export const projects = [
  {
    slug: 'work-aurora-coffee', name: 'Aurora Coffee', cover: 'cover-ember', kind: 'Brand identity and online shop', year: '2026',
    tags: ['Brand', 'Web'], role: 'Identity, website, packaging', stack: 'Kohevo Studio, Stripe checkout',
    summary: 'A roastery with a loyal local following wanted a brand that felt as warm online as the café does, and a shop that did not get in the way of the coffee.',
    challenge: 'Aurora sold through a marketplace page that looked like everyone else’s. Orders were fine, but regulars could not tell who roasted the beans, and wholesale enquiries were getting lost in direct messages.',
    steps: [['Listen', 'Two days in the roastery, tasting, packing and talking to the people who buy.'], ['Shape', 'A small identity system: one wordmark, three colours, a grid for labels that works on a bag and a screen.'], ['Build', 'A fast shop with a story-led home page, subscription option and a wholesale form that goes to the right inbox.']],
    results: [['3.1×', 'more wholesale enquiries in the first quarter'], ['−38%', 'time spent answering the same order questions'], ['4 wks', 'from first call to launch']],
    quote: ['We finally sound like ourselves online. People now say the website smells like the café.', 'Ines, founder of Aurora Coffee'],
  },
  {
    slug: 'work-kite-health', name: 'Kite Health', cover: 'cover-tide', kind: 'Product site and app interface', year: '2026',
    tags: ['Product', 'Web'], role: 'Product design, marketing site', stack: 'Design system, Kohevo Studio',
    summary: 'A care-coordination app for physiotherapists needed a product site that explained a complicated workflow in under a minute.',
    challenge: 'The app did a lot, so the old site tried to say everything. Clinics left before they reached the part that mattered: how a first week with Kite actually feels.',
    steps: [['Map', 'We mapped a clinic’s week and picked the three moments where Kite saves the most time.'], ['Simplify', 'One story, three chapters, a single call to action. The detail moved into tabs and answers.'], ['Prove', 'A short, honest demo flow and a calm interface that matched the product itself.']],
    results: [['2.4×', 'more demo requests from the same traffic'], ['61%', 'of visitors reach the pricing section'], ['6 wks', 'from kickoff to launch']],
    quote: ['It is the first time a supplier explained our own product back to us better than we do.', 'Daan, head of product at Kite Health'],
  },
  {
    slug: 'work-northbound-rail', name: 'Northbound Rail', cover: 'cover-dusk', kind: 'Timetable and journey planner', year: '2025',
    tags: ['Product'], role: 'UX research, interface design', stack: 'Design system, prototypes',
    summary: 'A regional rail operator wanted passengers to find their train in two taps, including on a platform with one bar of signal.',
    challenge: 'The existing planner was built around the timetable, not the traveller. Peak-hour disruptions turned it into a wall of red.',
    steps: [['Observe', 'Hours on platforms, watching how people actually check a delay.'], ['Rebuild', 'A journey-first layout with the next three trains, clear disruption wording and large touch targets.'], ['Test', 'Fifty-person usability rounds, each ending with a change we shipped the same week.']],
    results: [['2 taps', 'to see the next departure'], ['−27%', 'calls to the help line during disruptions'], ['AA', 'accessibility conformance on every screen']],
    quote: ['Passengers stopped asking us what the screen meant. That is the best review we could have wished for.', 'Sofie, digital lead at Northbound Rail'],
  },
  {
    slug: 'work-maple-and-co', name: 'Maple & Co.', cover: 'cover-moss', kind: 'Brand and packaging', year: '2025',
    tags: ['Brand'], role: 'Naming, identity, packaging', stack: 'Print and digital guidelines',
    summary: 'A third-generation syrup maker needed a brand that honoured the family story without looking like a museum piece.',
    challenge: 'Three generations of labels had produced three different logos. Shelf presence was weak and the story was buried on the back of the bottle.',
    steps: [['Gather', 'Old labels, photos and recipes laid out on one long table.'], ['Distil', 'A mark drawn from the sap line, a warm palette and one confident typeface.'], ['Roll out', 'Bottles, crates, a market stall and a one-page site that tells the story in order.']],
    results: [['+22%', 'sell-through at the first market season'], ['1', 'consistent look across every product'], ['3 wks', 'from brief to print-ready files']],
    quote: ['My grandfather would have recognised the care in it. And the shelves finally look like one family.', 'Joris, owner of Maple & Co.'],
  },
];

export const services = [
  { n: '01', name: 'Brand identity', line: 'A clear name, mark and voice that hold together from a business card to a billboard.', points: ['Naming and positioning', 'Logo and visual system', 'Voice and messaging', 'Guidelines your team will really use'] },
  { n: '02', name: 'Websites', line: 'Fast, accessible sites you can edit yourself, built as real blocks and not as a pasted page.', points: ['Content structure and copy', 'Design and build in Kohevo Studio', 'Forms, booking and payments', 'Launch, training and care'] },
  { n: '03', name: 'Product design', line: 'Interfaces that make a complicated thing feel obvious, tested with the people who use it.', points: ['Research and journey mapping', 'Prototypes and usability tests', 'Design systems', 'Hand-off and design reviews'] },
  { n: '04', name: 'Content and care', line: 'Words, photos and small improvements after launch, so the site keeps earning its place.', points: ['Copywriting and editing', 'Photography direction', 'Monthly improvements', 'Performance and accessibility checks'] },
];

export const process = [
  ['Discover', 'We start by listening. A short call, a look at what you have, and a plan for what to learn first.\n\nYou get: a one-page brief and a fixed timeline.'],
  ['Design', 'Quick sketches, then real screens. You see the site early, in the browser, with your words in it.\n\nYou get: two review rounds and a design you can click through.'],
  ['Build', 'We build it as editable blocks in Kohevo Studio, so every heading, image and button is yours to change.\n\nYou get: a staging site and a tidy handover.'],
  ['Launch', 'Redirects, speed, accessibility and analytics checked, then we go live together.\n\nYou get: a launch checklist and 30 days of care.'],
];

export const testimonials = [
  ['Fieldnote asked better questions in the first call than our last agency did in six months.', 'Ines, Aurora Coffee'],
  ['Calm, quick and very clear. We always knew what happened next, and the site is ours to edit.', 'Daan, Kite Health'],
  ['They treated the timetable like a human problem. That is why the redesign works on a cold platform.', 'Sofie, Northbound Rail'],
];

export const faq = [
  ['How long does a website take?', 'Most sites take four to eight weeks from the first call to launch. A simple one-page site can be ready in two. We agree a fixed timeline before we start.'],
  ['What does it cost?', 'Projects start at €4,500 for a focused site and €9,000 for a brand and website together. After a short call you get a fixed price, not an hourly estimate.'],
  ['Can I edit the site myself?', 'Yes. Everything is built as ordinary blocks in the Kohevo Studio builder. Change text, images and pages without a developer, and we train your team at handover.'],
  ['Do you work with small teams?', 'Most of our clients are teams of two to fifty. We work best with people who can decide quickly and want a partner, not a vendor.'],
  ['What happens after launch?', 'You get 30 days of free care. After that you can choose a monthly plan for improvements, or look after it yourself. Nothing is locked in.'],
  ['Where are you based?', 'Antwerp, Belgium. We work with clients across Europe and are happy to meet in person or over video.'],
];

export const team = [
  ['Mila Verhoeven', 'Founder, design lead', 'MV'],
  ['Arjun Rao', 'Development', 'AR'],
  ['Lotte De Smet', 'Brand and copy', 'LD'],
  ['Tom Janssens', 'Product design', 'TJ'],
];

export const values = [
  ['Clarity first', 'If a page cannot be explained in a sentence, it is not ready. We cut until it can.'],
  ['Make it editable', 'A site is only finished when the team that owns it can change it with confidence.'],
  ['Small and sharp', 'A senior team, a handful of projects at a time, and no hand-offs between strangers.'],
  ['Honest numbers', 'We measure what matters and tell you when something did not work.'],
];
