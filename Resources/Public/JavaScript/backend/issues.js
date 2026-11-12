/**
 * My issues (N-17, final customer acceptance): on a narrow screen the task table scrolls inside its frame; the frame
 * then becomes a named region keyboard users can focus and scroll (scrollable-tables.js), like the Overview tables.
 */
import { initializeScrollableTables } from '@priebera/a11y-quality-gate/backend/core/scrollable-tables.js';

initializeScrollableTables(document);
