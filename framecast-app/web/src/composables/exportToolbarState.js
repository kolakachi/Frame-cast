/**
 * What the export toolbar should say, given a finished export and whether the
 * project has moved on since it was made.
 *
 * The toolbar used to show "Export ready" and "Update video" side by side with
 * nothing to distinguish which applied, so pressing Update was the natural move
 * even when the video already matched. One project was rendered twice a second
 * apart that way — two charges of compute and two identical files in the list.
 *
 * Extracted from the template so the three derivations stay in step: the words
 * in the pill, the words on the button, and whether the button looks like the
 * next thing to do.
 */
export function exportToolbarState(job, outOfDate) {
  const completed = job?.status === 'completed';
  const stale = completed && !!outOfDate;

  return {
    // The pill only claims "ready" while the file still matches the project.
    status: !job
      ? ''
      : completed
        ? (stale ? 'Changes not exported' : 'Export ready')
        : job.status === 'failed'
          ? 'Export failed'
          : job.status === 'processing'
            ? `Exporting ${job.progress_percent || 0}%`
            : job.status === 'queued'
              ? 'Export queued'
              : `Export ${job.status}`,

    // "Update" implies there is something to update. When there is not, the
    // honest word is re-export, and it should not wear the primary style.
    action: !job ? 'Finish video' : (stale ? 'Update video' : 'Re-export'),

    primary: !job || stale,

    hint: completed && !stale
      ? 'This video already matches your project — no update needed'
      : '',

    stale,
  };
}
