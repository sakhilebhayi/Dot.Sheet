<!--
R-35. The owner's brief for Dot.Sheets, received 2026-11-03 and stored verbatim, as given.
The audit and plan that answer it are in docs/programme/R35_AUDIT.md.
-->

Dot.Sheets — Complete Product Transformation & Innovation Brief
1. The Vision: Make Spreadsheets Feel Effortless
Let's transform Dot.Sheets into one of the most intuitive, powerful and enjoyable spreadsheet platforms available.
The current Dot.Sheets experience has too much AI-generated-looking design, an unintuitive interface and unnecessary friction. It should not simply be another spreadsheet application with a different logo. It should fundamentally rethink how people create, manage, understand, analyse and collaborate around spreadsheet data.
The purpose of Dot.Sheets is to take the work out of creating spreadsheets.
People should not have to spend hours formatting cells, writing formulas, adjusting column widths, building tables, troubleshooting errors or figuring out how to turn raw information into something useful.
Dot.Sheets should make these activities feel natural.
A user should be able to arrive with an idea, a business problem, a dataset or even a simple instruction and quickly turn it into a professional, functional spreadsheet.
The experience should make users feel that spreadsheet work has become easier, faster and more enjoyable.
We are not building a simplified spreadsheet that sacrifices capability. We are building a more intelligent way to work with structured information.
Our ambition is to compete with and meaningfully differentiate from:

* Microsoft Excel
* Google Sheets
* Apple Numbers
* Zoho Sheet
* Airtable
* Smartsheet
* Other established spreadsheet and data management platforms

Study what makes these products successful, identify their frustrations and limitations, and build a product experience that goes beyond simply reproducing their features.
Do not blindly copy competitors. Find opportunities to rethink the spreadsheet experience itself.
2. Start With a Complete Product and Codebase Audit
Before implementing anything, inspect the existing Dot.Sheets repository and understand what already exists.
Use Laravel Boost to strengthen your understanding of the existing application and its backend architecture.
Audit:

* Existing application structure and technology stack.
* Spreadsheet editor and rendering engine.
* Workbook, worksheet, row, column and cell architecture.
* Formula engine and supported functions.
* Data storage and persistence.
* Import and export functionality.
* File handling and spreadsheet compatibility.
* Formatting and conditional formatting.
* Charts and visualisations.
* Collaboration and permissions.
* Sharing and publishing.
* Search and filtering.
* AI capabilities and integrations.
* API endpoints and background processing.
* Database design, relationships and migrations.
* Authentication, authorisation and tenant isolation.
* Responsive behaviour and accessibility.
* Existing UI components and design system.
* Performance when handling large spreadsheets.

For every capability, establish whether it is:

1. Fully implemented and working.
2. Partially implemented.
3. Present in the interface but not functionally connected.
4. Simulated or dependent on placeholder data.
5. Broken or unreliable.
6. Completely missing.

Do not rebuild functioning features unnecessarily. Preserve good architecture, identify weak foundations and improve them.
The audit must establish what can be reused, what needs refactoring and what requires new implementation.
3. Design Direction: Eliminate AI Slop Completely
The current interface needs a serious design rethink.
Use every relevant installed and available skill before designing or implementing the experience.
This includes:

* Product design and product strategy.
* UX research and interaction design.
* UI/UX design.
* Design systems and component architecture.
* Visual hierarchy and information architecture.
* Typography and layout.
* Branding and visual identity.
* Human-centred design.
* User psychology and behavioural design.
* Accessibility and responsive design.
* Motion design and scroll motion.
* Microinteractions and transitions.
* Content design and UX writing.
* Copywriting.
* Frontend engineering and performance optimisation.

Read the installed skill instructions and supporting references before applying them. Do not merely mention the skills in the final report.
The design philosophy
Dot.Sheets should feel:

* Effortless without feeling basic.
* Sophisticated without feeling complicated.
* Powerful without overwhelming users.
* Creative without becoming distracting.
* Professional without feeling corporate and lifeless.
* Intelligent without constantly advertising its AI.
* Familiar enough to learn quickly, but distinctive enough to be memorable.

Align Dot.Sheets with the visual quality and design language of the wider Dot ecosystem while preserving a distinctive product identity.
Avoid:

* Generic AI-generated SaaS layouts.
* Excessive gradients and glassmorphism.
* Unnecessary floating cards.
* Oversized headings.
* Random decorative icons.
* Excessive whitespace that wastes working space.
* Unnecessary toolbars and nested menus.
* Complicated settings panels.
* Robotic AI-generated copy.
* Decorative animations that slow down actual work.
* Features that exist only to make screenshots look impressive.

Every element must have a clear purpose.
Make the spreadsheet the centre of attention
The spreadsheet canvas must be the heart of Dot.Sheets.
Users should spend their time working with information, not navigating an interface.
Create a clean, intelligent working environment with:

* A carefully designed workbook navigation area.
* An intuitive formula and input experience.
* Context-aware formatting controls.
* A clear worksheet navigation system.
* Smart contextual menus.
* Efficient selection and editing interactions.
* A well-designed status and information area.
* A powerful but unobtrusive command/search experience.

Do not permanently expose every possible control. Show the tools users need when they need them.
4. The Core Experience: Create Spreadsheets Without the Frustration
This is the most important part of the product.
Dot.Sheets must rethink the process of creating spreadsheets.
A user should not have to start with a blank grid and figure everything out.
Multiple ways to create a spreadsheet
Support the following creation experiences:
A. Start with an idea
Users describe what they want to achieve.
Examples:

* Create a monthly business expense tracker.
* Build an employee attendance register.
* Create a project budget.
* Build a mining equipment operating-hours report.
* Create a sales performance tracker.
* Build a stock inventory register.
* Create a monthly financial summary.

Dot.Sheets should turn these instructions into usable workbooks with appropriate worksheets, columns, formulas, formatting, validations and summaries.
B. Start with existing data
Allow users to upload or import supported files and turn them into structured workbooks.
C. Start with a template
Offer professionally designed, genuinely functional templates for common personal and business activities.
D. Start from scratch
Provide a clean, intuitive blank workbook for users who prefer complete control.
E. Start from an existing workbook
Allow users to duplicate, adapt and improve an existing spreadsheet without destroying the original.
The creation experience should be quick, guided and intelligent.
5. Build a Spreadsheet Platform With Professional-Grade Capabilities
Dot.Sheets must deliver the depth expected from a serious spreadsheet application.
5.1 Workbook and worksheet management
Support:

* Multiple workbooks.
* Multiple worksheets per workbook.
* Worksheet creation, deletion, duplication and renaming.
* Drag-and-drop worksheet ordering.
* Worksheet colours and organisation.
* Worksheet protection.
* Hidden and visible worksheets.
* Workbook-level settings.
* Cross-sheet references.
* Named ranges.
* Workbook templates.
* Workbook duplication.
* Automatic saving and recovery.
* Version history.
* Restore previous versions.
* Workbook comments and annotations.

Users should be able to organise complex workbooks without losing control.
5.2 Cell editing and formatting
Provide intuitive cell editing and professional formatting capabilities:

* Font family, size, weight and style.
* Text and background colours.
* Borders and cell alignment.
* Number, date, time and currency formats.
* Percentage and decimal formatting.
* Text wrapping.
* Cell merging.
* Row height and column width.
* Auto-fit.
* Freeze panes.
* Hidden rows and columns.
* Cell protection.
* Data validation.
* Copy, cut and paste.
* Fill handles and intelligent autofill.
* Multi-cell selection.
* Find and replace.
* Undo and redo.
* Keyboard shortcuts.
* Contextual right-click actions.

Formatting should be easy to discover and apply.
Introduce sensible formatting suggestions where they genuinely help users.
5.3 Formula and calculation engine
Formulas are fundamental to spreadsheet software.
Build a reliable formula engine that supports commonly used spreadsheet functions and grows towards professional-grade compatibility.
Include:

* Mathematical functions.
* Statistical functions.
* Logical functions.
* Text functions.
* Date and time functions.
* Lookup and reference functions.
* Financial functions.
* Conditional aggregation.
* Array operations.
* Dynamic arrays where supported.
* Cross-worksheet calculations.
* Named ranges.
* Formula auditing.
* Error identification.
* Formula dependency tracking.
* Circular reference detection.
* Calculation optimisation.

Examples of important functions include SUM, AVERAGE, COUNT, COUNTA, COUNTIF, COUNTIFS, SUMIF, SUMIFS, IF, IFS, AND, OR, IFERROR, XLOOKUP, VLOOKUP, INDEX, MATCH, FILTER, SORT, UNIQUE, LEFT, RIGHT, MID, TEXT, TODAY, NOW, DATE, EOMONTH and PMT.
Do not simply display formula names in an autocomplete menu. Ensure that implemented functions calculate correctly.
5.4 Make formulas easier for humans
Many spreadsheet users struggle with formulas.
Make formula creation less intimidating.
Introduce:

* Contextual formula suggestions.
* Formula autocomplete.
* Plain-language formula explanations.
* Visual formula construction.
* Function descriptions and examples.
* Formula error explanations.
* Formula repair suggestions.
* Automatic formula propagation where appropriate.
* Formula dependency visualisation.
* A guided function assistant.

For example, a user should be able to ask:
"Calculate the total expenses for each department."
Dot.Sheets should help construct the correct formula using the actual workbook structure.
Users must remain able to inspect, edit and understand generated formulas.
6. Intelligent Spreadsheet Creation and AI Assistance
AI should be one of Dot.Sheets' strongest advantages, but it must feel like a natural part of the product.
Do not turn Dot.Sheets into a chatbot with a spreadsheet attached.
AI should reduce effort across the entire spreadsheet lifecycle.
AI spreadsheet assistant
Users should be able to ask questions and give instructions using natural language.
Examples:

* Create a monthly expense report from this data.
* Identify missing values.
* Find duplicate employee records.
* Calculate the average monthly production.
* Highlight expenses above R50,000.
* Summarise performance by department.
* Explain why this formula is returning an error.
* Create a chart comparing monthly revenue.
* Build a dashboard from this workbook.
* Identify unusual changes in the data.
* Clean and standardise inconsistent entries.

AI-powered workbook creation
Generate complete, structured spreadsheets from instructions.
AI should understand:

* The intended purpose.
* The type of information required.
* Appropriate worksheet structures.
* Column names and data types.
* Formula requirements.
* Validation rules.
* Formatting.
* Summary sections.
* Relevant charts.

Where actual source data is unavailable, create a structural template without pretending that example figures are real business information.
AI-powered data cleaning
Help users:

* Identify duplicate records.
* Standardise dates and text.
* Detect inconsistent formats.
* Find missing values.
* Identify outliers.
* Detect unusual patterns.
* Validate data.
* Recommend corrections.

Always preview potentially destructive changes and allow users to approve them.
AI-powered analysis
Allow users to ask questions about their data and receive:

* Relevant calculations.
* Summaries.
* Trends.
* Comparisons.
* Explanations.
* Suggested visualisations.
* Potential anomalies.
* Useful business observations.

Every analytical claim must be traceable to the actual workbook data.
Clearly distinguish observed facts, calculations, assumptions and AI-generated interpretations.
Human approval
AI must not silently overwrite data, delete records, modify formulas or change workbook structures.
Show proposed changes, explain their impact and obtain user approval before applying consequential modifications.
Maintain a record of AI-assisted changes where appropriate.
7. Make Data Presentation Beautiful and Effortless
Spreadsheets should not have to look like endless rows and columns.
Dot.Sheets should make it easy to turn information into professional business outputs.
Charts and visualisations
Support:

* Bar charts.
* Column charts.
* Line charts.
* Area charts.
* Pie and doughnut charts.
* Scatter plots.
* Histograms.
* Waterfall charts.
* Combination charts.
* Pivot charts.
* Sparklines.
* Customisable chart formatting.

Allow users to create charts through selection, contextual actions or natural-language instructions.
Charts should remain connected to their source data and update correctly when that data changes.
Pivot tables and data exploration
Build a powerful pivot table experience:

* Drag-and-drop row and column fields.
* Values and aggregation settings.
* Filters and sorting.
* Grouping.
* Calculated fields where supported.
* Drill-down into source records.
* Refresh and update.
* Pivot charts.
* Saved pivot configurations.

Make data exploration accessible to people who do not understand advanced spreadsheet techniques.
Conditional formatting
Allow users to highlight meaningful information through:

* Colour scales.
* Data bars.
* Icon indicators.
* Duplicate values.
* Missing values.
* Thresholds.
* Formula-based rules.
* Custom rules.

Make the rule-building experience visual and intuitive.
Dashboards
Allow users to create dashboard worksheets that combine:

* Key metrics.
* Charts.
* Summary tables.
* Trends.
* Performance indicators.
* Filter controls.
* Relevant explanatory notes.

Dashboards should be professional enough to present to management without requiring a separate design application.
8. Make Dot.Sheets Useful for Real Businesses
Dot.Sheets should serve a wide range of business functions rather than focusing only on general-purpose spreadsheets.
Create a strong template and workflow ecosystem.
Business and administration

* Expense trackers.
* Budgets.
* Cash-flow forecasts.
* Invoice registers.
* Procurement trackers.
* Supplier registers.
* Asset registers.
* Inventory management.
* Sales reports.
* Customer databases.

Human resources

* Employee registers.
* Attendance registers.
* Leave trackers.
* Training registers.
* Recruitment trackers.
* Performance records.
* Workforce planning.

Project management

* Project budgets.
* Task trackers.
* Resource plans.
* Project schedules.
* Risk registers.
* Progress reports.
* Milestone trackers.

Operations and industrial environments

* Equipment registers.
* Maintenance schedules.
* Operating-hour reports.
* Production summaries.
* Shift reports.
* Fuel consumption analysis.
* Downtime analysis.
* Operational performance trackers.

For mining and industrial templates, support operational measurements such as BCMs, operating hours, equipment utilisation, cycle performance and fuel consumption where relevant.
Templates must be genuinely functional, with working formulas, validation and appropriate formatting.
Do not fill templates with fabricated business records and present them as real data.
9. Collaboration and Shared Workbooks
Dot.Sheets must work for individuals, teams and organisations.
Support:

* Sharing workbooks with authorised users.
* Business-level workbook sharing.
* Team and department access.
* View-only permissions.
* Editing permissions.
* Workbook ownership.
* Worksheet-level protection where technically supported.
* Controlled collaboration.
* Comments and discussions.
* Cell or range comments.
* Mentions and notifications.
* Change history.
* Concurrent editing where supported by the architecture.
* Conflict resolution.
* Shared templates.
* Approval workflows for controlled workbooks.

Clearly communicate who can access and edit a workbook.
Permission enforcement must happen on the server, not just in the interface.
Real-time collaboration
Where feasible, implement reliable real-time collaboration with:

* Live presence.
* User activity indicators.
* Concurrent editing.
* Conflict-safe updates.
* Synchronisation.
* Recovery after connection loss.

Do not claim real-time collaboration if updates are actually delayed or periodically refreshed.
10. Import, Export and Compatibility
Users must be able to move their existing spreadsheet work into Dot.Sheets without unnecessary friction.
Support the relevant formats, prioritising:

* XLSX.
* XLS where technically feasible.
* CSV.
* TSV.
* ODS.
* PDF export.
* Spreadsheet printing.

Preserve as much as possible:

* Values.
* Formulas.
* Formatting.
* Worksheets.
* Column widths and row heights.
* Merged cells.
* Charts where supported.
* Data validation.
* Conditional formatting.

Clearly report compatibility limitations when importing complex workbooks.
Never silently discard important workbook content.
Provide export options for individual worksheets and complete workbooks where appropriate.
11. A Spreadsheet Experience That Feels Enjoyable
Dot.Sheets should make everyday spreadsheet work feel less tedious.
Think carefully about the small interactions that create a great product.
Examples:

* Instant, predictable keyboard interactions.
* Smooth worksheet navigation.
* Intelligent autofill.
* Contextual formatting suggestions.
* Smart column sizing.
* Quick table creation.
* One-click formatting.
* Clear formula feedback.
* Helpful empty states.
* Useful onboarding.
* Natural-language assistance.
* Fast search.
* Easy undo and recovery.
* Clean notifications.
* Pleasant but subtle transitions.

Introduce purposeful microinteractions and scroll motion where they improve understanding and perceived quality.
Do not animate the spreadsheet grid unnecessarily. Performance and responsiveness must always come first.
Support reduced-motion preferences.
Copywriting and product language
Use the installed content design and copywriting skills to improve every interaction.
The language should be:

* Human.
* Clear.
* Concise.
* Encouraging.
* Professional.
* Context-aware.
* Free from unnecessary technical jargon.

Avoid robotic messages such as generic AI-generated explanations.
Every button, label, tooltip, error, notification, empty state and onboarding instruction should help users understand what to do next.
The product should feel welcoming to beginners without frustrating experienced spreadsheet users.
12. Dot Ecosystem Integration
Dot.Sheets must feel like a natural part of the Dot ecosystem.
InfoDot remains the central identity and authentication gateway.
Users should not need another independent account to use Dot.Sheets.
Explore genuine integration opportunities with:

* InfoDot: Central identity, business context and knowledge.
* Dot.Files: Secure workbook storage, file management and versioning.
* Dot.Docs: Use spreadsheet information in business documents and reports.
* Dot.Forms: Turn form submissions into structured spreadsheet data where supported.
* Dot.Projects: Project budgets, resources, progress and reporting.
* Dot.Tasks: Task tracking and operational registers.
* Dot.HR: Workforce and employee-related spreadsheet workflows.
* Dot.Finance: Financial information and reporting.
* Dot.Analytics: Advanced analysis and business intelligence.
* Dot.Notify: Notifications and workflow updates.
* Dot.Agents: Authorised AI-assisted spreadsheet workflows.
* Dot.Brain: Reusable knowledge and approved spreadsheet guidance.

These are integration opportunities, not permission to invent integrations.
Inspect the actual repositories, APIs, authentication mechanisms and deployed capabilities before implementing connections.
Use secure service-to-service communication, scoped permissions, tenant-aware data access, idempotency and reliable error handling.
Avoid unnecessary duplication of data between platforms.
13. Enterprise-Grade Backend and Engineering
Use Laravel Boost throughout the backend assessment and implementation process.
Strengthen the application beyond its current state.
Review and improve:

* Database schema and relationships.
* Workbook and worksheet persistence.
* Cell and range storage strategy.
* Formula calculation architecture.
* API design.
* Livewire components.
* Services and domain boundaries.
* Jobs and queues.
* Events and listeners.
* Caching.
* Search and filtering.
* File processing.
* Import and export pipelines.
* Validation.
* Policies and authorisation.
* Middleware.
* Logging and monitoring.
* Automated testing.

Performance
Spreadsheet applications can become resource-intensive.
Design for:

* Large worksheets.
* Large workbooks.
* Efficient cell rendering.
* Virtualised rows and columns where appropriate.
* Efficient range updates.
* Optimised recalculation.
* Background processing for expensive operations.
* Efficient imports and exports.
* Caching of appropriate derived results.
* Reliable autosaving.
* Recovery after browser or connection failure.

Avoid architectures that require loading every cell into the browser simultaneously.
Choose an appropriate spreadsheet rendering and calculation strategy based on actual technical evaluation. Do not build an unnecessarily complex engine when a suitable, maintainable solution exists.
Security and data integrity
Enforce:

* Strict business and tenant isolation.
* Server-side permissions.
* Secure workbook sharing.
* Protected sensitive information.
* Input validation.
* Safe file processing.
* Upload restrictions.
* Protection against formula injection in exports.
* Audit logs.
* Rate limiting.
* Secure API authentication.
* Reliable backups and restoration.
* Version integrity.
* Safe concurrent updates.

A user must never be able to access another business's workbook by changing a URL, identifier, API request or worksheet reference.
14. Product Architecture and Future Extensibility
Build Dot.Sheets as a platform that can grow without becoming difficult to maintain.
Establish clear separation between:

* Workbook management.
* Worksheet management.
* Cell and range operations.
* Formula calculation.
* Formatting.
* Data validation.
* Charts and visualisations.
* Import and export.
* Collaboration.
* Permissions.
* AI assistance.
* Templates.
* Integrations.
* Analytics.

Where appropriate, expose reusable APIs and events so other Dot platforms can interact with spreadsheets securely.
Do not tightly couple every capability into one oversized component or service.
The architecture must support future expansion without requiring a complete rewrite.
15. Implementation Roadmap
Do not attempt to implement everything as one uncontrolled change.
Work in deliberate phases.
Phase 1 — Audit and product strategy

* Inspect the complete repository.
* Review installed skills.
* Audit current UX and functionality.
* Identify technical weaknesses.
* Establish the product architecture and implementation priorities.

Phase 2 — Design transformation

* Establish the Dot.Sheets visual identity.
* Redesign the primary workbook experience.
* Improve navigation, toolbars, menus and editing interactions.
* Build a coherent design system.
* Validate the experience across screen sizes.

Phase 3 — Spreadsheet foundation

* Strengthen workbook and worksheet management.
* Improve cell editing and formatting.
* Improve keyboard navigation.
* Implement reliable autosave and recovery.
* Strengthen formula calculation and data integrity.

Phase 4 — Intelligent creation

* Build the natural-language spreadsheet creation experience.
* Improve templates.
* Introduce guided formula assistance.
* Implement data cleaning and intelligent formatting.

Phase 5 — Advanced spreadsheet capabilities

* Charts.
* Pivot tables.
* Conditional formatting.
* Data validation.
* Dashboards.
* Advanced formula functionality.

Phase 6 — Collaboration and enterprise

* Sharing and permissions.
* Comments and collaboration.
* Version history.
* Import and export.
* Enterprise security and governance.

Phase 7 — Dot ecosystem integration

* Connect only verified, working platform capabilities.
* Implement secure APIs and event-driven workflows.
* Test cross-platform permissions and data integrity.

Phase 8 — Production readiness

* Performance testing.
* Security testing.
* Accessibility testing.
* End-to-end workflows.
* Failure recovery.
* Deployment readiness.

Prioritise the capabilities that remove the greatest amount of friction from everyday spreadsheet work.
16. Mandatory Testing and Acceptance Criteria
Do not consider Dot.Sheets complete because the interface looks better.
Test actual working functionality.
At minimum, verify:

1. Create a workbook from scratch.
2. Generate a workbook from a natural-language instruction.
3. Create, rename, reorder and delete worksheets.
4. Enter, edit, copy and paste data.
5. Format cells, rows and columns.
6. Create and calculate formulas.
7. Detect and explain formula errors.
8. Apply conditional formatting.
9. Create and edit charts.
10. Create and refresh pivot tables if implemented.
11. Import XLSX and CSV files.
12. Export workbooks without corrupting content.
13. Save, close and reopen workbooks.
14. Recover from interrupted connections.
15. Restore an earlier version.
16. Share a workbook with authorised users.
17. Verify access restrictions.
18. Test tenant isolation.
19. Test AI-generated changes and human approval.
20. Test large workbook performance.
21. Test keyboard navigation and accessibility.
22. Test responsive behaviour.

Add automated unit, integration and browser-level tests where appropriate.
Never report a feature as complete if it only works visually or uses placeholder data.
17. Definition of Success
Dot.Sheets should achieve the following:

* A beginner can create a useful spreadsheet without needing to understand spreadsheet design.
* A professional can work with complex spreadsheets without sacrificing capability.
* Businesses can standardise common spreadsheet workflows using reliable templates.
* Users can turn raw data into meaningful information quickly.
* AI removes repetitive work while users retain control.
* Collaboration feels natural and secure.
* Existing spreadsheet files can be brought into Dot.Sheets with transparent compatibility.
* The application feels fast, polished and enjoyable.
* The design feels like a premium, intentional Dot product rather than an AI-generated SaaS template.
* The backend is secure, maintainable, scalable and properly tested.

18. Final Product Principle
Never lose sight of the central purpose:
Dot.Sheets exists to take the work out of spreadsheets.
A spreadsheet should not begin with frustration, endless formatting or complicated formulas. It should begin with what the user wants to achieve.
The platform should handle the tedious work, make intelligent suggestions, organise information, simplify calculations and present results beautifully.
The user should remain in control without having to do everything manually.
Build a spreadsheet platform that people genuinely enjoy opening and using — one that makes them question why spreadsheet work ever had to be so difficult.
Do not settle for a visual refresh. Do not settle for a collection of disconnected features. Do not settle for an AI assistant attached to a basic grid.
Build Dot.Sheets as a complete, intelligent, business-ready spreadsheet experience.
Dot.Sheets — Less spreadsheet work. More meaningful work.
19. Final Delivery Report
At the end of implementation, provide a clear report covering:

* What was discovered during the initial audit.
* What was redesigned.
* What features were implemented.
* What existing functionality was improved.
* What backend changes were made.
* What integrations are genuinely operational.
* What automated and manual tests were executed.
* Test results and evidence.
* Performance and security findings.
* Known limitations.
* Outstanding work.
* Remaining production blockers.

Separate fully completed work from partially completed work.
Do not claim success based on screenshots, design previews or code that has not been tested.
The objective is a genuinely improved product that users can rely on, not an impressive-looking demonstration.
