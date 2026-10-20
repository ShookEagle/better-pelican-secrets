<?php

return [
    'revealed' => 'Value revealed. This access was audited.',
    'source_uncertain' => 'The source may have changed, but its binding plan was not confirmed. Reopen the file, review it, and retry. Setup remains invalid/pending.',
    'source_conflict' => 'The source changed after you opened it. Reopen and review before saving again.',
    'confirmation_required' => 'Confirm the format, reference approval, and migration choices before saving.',
    'node_incompatible' => 'This node needs the matching secrets-enabled Wings release before bindings can be saved.',
    'saved' => 'Saved. Runtime changes apply on the next normal start or restart.',
    'failed' => 'Unable to save. Check your permissions, inputs, and active bindings.',
    'blank_preserves' => 'Leave blank to keep the current value. Existing values are never prefilled.',
    'pending' => 'Saved changes are pending the next normal start or restart.',
    'runtime_note' => 'Game code can read runtime secrets. Review executable changes from developers.',
    'readonly' => 'Rendered files are read-only in the game. Editable source files keep secret markers.',
];
