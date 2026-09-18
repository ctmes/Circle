import { useCallback, useRef, useState, type DragEvent } from "react";
import { uploadEvidence, walkTransfer } from "../lib/upload";

/**
 * Dropping files onto a piece of work.
 *
 * The gesture the product was missing. Evidence could only enter through the
 * vault, which meant that filing the signed drawing against the package it
 * belongs to was a three-screen errand — upload in Context, find the job, then
 * author a claim to connect them — and the predictable result was a vault of
 * 400 files and a plan that pointed at none of them.
 *
 * One hook rather than a component, because the target is whatever the screen
 * already draws: a row in the tree, a row in the work list, the file panel on
 * the job itself. Spreading `dropProps` onto any of those makes it a target,
 * and `dragging` is there so the row can say so.
 *
 * Two refusals worth noting. A drag carrying no files — a text selection, a
 * link, a row being dragged from elsewhere — is ignored rather than swallowed,
 * so the browser's own handling survives. And a target that cannot accept
 * files (no permission, a closed Circle, a node a branch only proposes) never
 * highlights, because a drop zone that refuses on release is worse than one
 * that was never offered.
 */
export function useJobDrop({
  circleId,
  goalId,
  enabled = true,
  onFiled,
}: {
  circleId: string;
  /** Null for a node a branch proposes adding: nothing exists to file against. */
  goalId: string | null;
  enabled?: boolean;
  onFiled: (count: number) => void;
}) {
  const [dragging, setDragging] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [progress, setProgress] = useState<{ done: number; total: number } | null>(null);

  /*
    dragenter/dragleave fire for every child element the pointer crosses, so a
    row with a twisty, a title and three columns flickers if the flag is driven
    by the events alone. Counting entries and exits is the standard fix.
  */
  const depth = useRef(0);

  const live = enabled && goalId !== null;

  const carriesFiles = (e: DragEvent) =>
    Array.from(e.dataTransfer?.types ?? []).includes("Files");

  const onDragEnter = useCallback(
    (e: DragEvent) => {
      if (!live || !carriesFiles(e)) return;
      e.preventDefault();
      depth.current += 1;
      setDragging(true);
    },
    [live],
  );

  const onDragOver = useCallback(
    (e: DragEvent) => {
      if (!live || !carriesFiles(e)) return;
      // Without both the preventDefault and the explicit effect, the browser
      // shows a "no drop" cursor over a target that will in fact accept it.
      e.preventDefault();
      e.dataTransfer.dropEffect = "copy";
    },
    [live],
  );

  const onDragLeave = useCallback(
    (e: DragEvent) => {
      if (!live || !carriesFiles(e)) return;
      depth.current = Math.max(0, depth.current - 1);
      if (depth.current === 0) setDragging(false);
    },
    [live],
  );

  const onDrop = useCallback(
    (e: DragEvent) => {
      if (!live || !carriesFiles(e)) return;

      e.preventDefault();
      // A row inside the tree, inside the page: without this the same files
      // land on every ancestor that is also a target.
      e.stopPropagation();

      depth.current = 0;
      setDragging(false);
      setError(null);

      const transfer = e.dataTransfer;

      void (async () => {
        setBusy(true);
        try {
          const picked = await walkTransfer(transfer);

          if (picked.length === 0) return;

          setProgress({ done: 0, total: picked.length });

          const result = await uploadEvidence({
            circleId,
            picked,
            goalId,
            onState: (_path, state) => {
              if (state === "done" || state === "failed") {
                setProgress((p) => (p === null ? p : { ...p, done: p.done + 1 }));
              }
            },
          });

          if (result.rejected.length > 0 && result.createdCount === 0) {
            throw new Error(
              result.rejected.length === 1
                ? `${result.rejected[0].filename} was refused: ${result.rejected[0].reason}.`
                : `${result.rejected.length} files were refused.`,
            );
          }

          onFiled(result.createdCount);
        } catch (err) {
          setError(err);
        } finally {
          setBusy(false);
          setProgress(null);
        }
      })();
    },
    [circleId, goalId, live, onFiled],
  );

  return {
    /** True while a drag carrying files is over this target. */
    dragging,
    busy,
    progress,
    error,
    clearError: () => setError(null),
    dropProps: { onDragEnter, onDragOver, onDragLeave, onDrop },
  };
}
