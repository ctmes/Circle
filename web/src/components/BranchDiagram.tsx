import { useEffect, useMemo, useRef, useState } from "react";
import { formatDate, type Circle, type Goal } from "../lib/api";

/**
 * The plan as a branch diagram — the shape a git history has, with time across.
 *
 * One rule draws the whole thing: **a leaf goal is a dot, a goal with children
 * is a branch.** Legal is a branch because work hangs off it; "contract review"
 * forks its own branch off Legal because work hangs off *it*; "send the
 * engrossment" is a dot. Branching off a branch is depth, capped at four levels
 * server-side, so the drawing can never nest deeper than that.
 *
 * The axis is the Circle's own lifespan. That is what makes a to-scale timeline
 * work here where it usually does not: a Circle is temporary and has a start and
 * an expiry, so the axis has natural bounds, and the crunches and gaps in a
 * schedule are real distances rather than the even spacing a git graph settles
 * for.
 *
 * **Nothing is written inside the plot.** The first version labelled every dot
 * and stacked the titles on two levels to stop them colliding; at thirty goals
 * it was a wall of eight-point type with a diagram somewhere behind it. Names
 * live in the gutter, where there is room and they line up; a dot answers on
 * hover and on click. What is left in the plot is position, colour and fill —
 * which is all the shape actually needs.
 */

const LABEL_W = 214;
const ROW_H = 58;
const PAD_TOP = 34;
const PAD_BOTTOM = 22;
/** Where undated work stands, past the end of the axis. */
const GUTTER_W = 100;
const DOT_R = 7;
/**
 * How far a fork or merge reaches sideways, given how far it reaches down.
 *
 * A branch has to actually meet the line it came off — a stub that stops in
 * mid-air reads as a disconnected fragment, which is exactly what it looked
 * like. Scaling the horizontal run with the vertical drop is what keeps the
 * long ones (a top-level branch rejoining the mission seven rows up) reading as
 * curves rather than as walls.
 */
function curveFor(dy: number): number {
  return Math.min(84, 22 + Math.abs(dy) * 0.19);
}

/**
 * Closest two dots may sit before the later one is nudged along.
 *
 * Two goals due the same day land on the same pixel and read as one mark. The
 * nudge moves the second a few pixels right of true — a lie of about a day at
 * this scale, and a smaller one than drawing two things as one.
 */
const MIN_GAP = 17;

type Dot = { goal: Goal; x: number; dated: boolean };

type Lane = {
  /** Null for the Circle's own line — the "main" every root branches off. */
  goal: Goal | null;
  row: number;
  /** The row this branch forks from and merges back into. Null for the mission. */
  parentRow: number | null;
  depth: number;
  forkX: number;
  mergeX: number;
  dots: Dot[];
  hue: number;
};

/**
 * A stable colour per party.
 *
 * Parties carry no colour in the schema, and adding one is a migration plus a
 * picker. A hash of the id gives every company its own hue, consistently,
 * everywhere it appears. Mid saturation and lightness so one value reads on
 * paper and in the dark.
 */
export function hueFor(id: string | null | undefined): number {
  if (!id) return 220;
  let h = 0;
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) % 360;
  // Skirt the reds, which this palette reserves for overdue and refusal.
  return (h % 320) + 20;
}

export const stroke = (hue: number, alpha = 1) => `hsl(${hue} 58% 48% / ${alpha})`;

const hasKids = (g: Goal) => (g.children?.length ?? 0) > 0;

function time(v: string | null | undefined): number | null {
  if (!v) return null;
  const t = Date.parse(v);
  return Number.isNaN(t) ? null : t;
}

function datesIn(goals: Goal[]): number[] {
  return goals
    .flatMap((g) => [time(g.starts_at), time(g.due_at), ...datesIn(g.children ?? [])])
    .filter((t): t is number => t !== null);
}

export function BranchDiagram({
  goals,
  circle,
  selectedId,
  onSelect,
}: {
  goals: Goal[];
  circle: Circle | null;
  selectedId: string | null;
  onSelect: (goalId: string | null) => void;
}) {
  const wrap = useRef<HTMLDivElement>(null);
  const [width, setWidth] = useState(1040);

  useEffect(() => {
    const el = wrap.current;
    if (!el) return;
    const ro = new ResizeObserver(([e]) => setWidth(Math.max(e.contentRect.width, 700)));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);

  const layout = useMemo(() => build(goals, circle, width), [goals, circle, width]);

  return (
    <div ref={wrap} className="overflow-x-auto">
      {layout && (
        <svg
          width={layout.width}
          height={layout.height}
          role="img"
          aria-label="The plan, drawn as branches over time"
          className="block"
        >
          <Axis layout={layout} />
          {layout.lanes.map((l) => (
            <LaneLine key={l.row} lane={l} />
          ))}
          {layout.lanes.map((l) =>
            l.dots.map((d) => (
              <DotMark
                key={d.goal.id ?? `${l.row}-${d.x}`}
                dot={d}
                lane={l}
                selected={d.goal.id !== null && d.goal.id === selectedId}
                onSelect={onSelect}
              />
            )),
          )}
          {layout.lanes.map((l) => (
            <LaneLabel
              key={`l-${l.row}`}
              lane={l}
              selected={l.goal?.id != null && l.goal.id === selectedId}
              onSelect={onSelect}
            />
          ))}
        </svg>
      )}
    </div>
  );
}

// ------------------------------------------------------------------- layout

type Layout = {
  lanes: Lane[];
  width: number;
  height: number;
  plotR: number;
  todayX: number | null;
  expiryX: number | null;
  ticks: Array<{ x: number; label: string }>;
};

function build(goals: Goal[], circle: Circle | null, width: number): Layout | null {
  if (goals.length === 0) return null;

  const plotL = LABEL_W;
  const plotR = width - GUTTER_W;
  const span = Math.max(plotR - plotL, 240);

  const all = datesIn(goals);
  const cStart = time(circle?.starts_at) ?? time(circle?.created_at);
  const cEnd = time(circle?.expires_at);

  // The Circle's own span wins where it exists; the goals widen it, never narrow
  // it. Work that all lands in one week is still drawn against its mission.
  let t0 = Math.min(...[cStart, ...all].filter((t): t is number => t !== null));
  let t1 = Math.max(...[cEnd, ...all].filter((t): t is number => t !== null));
  if (!Number.isFinite(t0) || !Number.isFinite(t1)) {
    t0 = Date.now();
    t1 = t0 + 30 * 86400000;
  }
  if (t1 - t0 < 86400000) t1 = t0 + 86400000;
  const pad = (t1 - t0) * 0.04;
  t0 -= pad;
  t1 += pad;

  const x = (t: number) => plotL + ((t - t0) / (t1 - t0)) * span;

  const lanes: Lane[] = [];
  let nextRow = 1;

  function dotsFor(kids: Goal[]): Dot[] {
    const dots = kids
      .filter((k) => !hasKids(k))
      .map((k) => {
        const t = time(k.due_at);
        return t !== null
          ? { goal: k, x: x(t), dated: true }
          : { goal: k, x: plotR + 24, dated: false };
      })
      .sort((a, b) => a.x - b.x);

    // Two goals on the same date would otherwise be drawn as one dot.
    let prev = -Infinity;
    for (const d of dots) {
      if (d.x - prev < MIN_GAP) d.x = prev + MIN_GAP;
      prev = d.x;
    }

    return dots;
  }

  function extent(goal: Goal, parentForkX: number): [number, number] {
    const kd = (goal.children ?? [])
      .flatMap((k) => [time(k.starts_at), time(k.due_at)])
      .filter((t): t is number => t !== null);
    const s = time(goal.starts_at);
    const d = time(goal.due_at);
    const forkX = s !== null ? x(s) : kd.length ? x(Math.min(...kd)) : parentForkX;
    const mergeX = d !== null ? x(d) : kd.length ? x(Math.max(...kd)) : forkX + 60;
    // A floor wide enough to carry a fork curve, a run of line and a merge
    // curve. Below it a branch collapses into a knot of its own connectors —
    // which is what a goal with no date of its own and two children a day apart
    // was drawing.
    return [forkX, Math.min(Math.max(mergeX, forkX + 150), plotR)];
  }

  function walk(goal: Goal, depth: number, parentRow: number, parentForkX: number) {
    const row = nextRow++;
    const [forkX, mergeX] = extent(goal, parentForkX);
    lanes.push({
      goal,
      row,
      parentRow,
      depth,
      forkX,
      mergeX,
      dots: dotsFor(goal.children ?? []),
      hue: hueFor(goal.responsible_party?.id ?? goal.owner?.id ?? goal.id),
    });
    for (const k of goal.children ?? []) if (hasKids(k)) walk(k, depth + 1, row, forkX);
  }

  const mainFork = x(t0 + (t1 - t0) * 0.02);
  lanes.push({
    goal: null,
    row: 0,
    parentRow: null,
    depth: 0,
    forkX: mainFork,
    mergeX: cEnd !== null ? x(cEnd) : plotR,
    dots: dotsFor(goals),
    hue: hueFor(circle?.id),
  });
  for (const r of goals) if (hasKids(r)) walk(r, 1, 0, mainFork);

  return {
    lanes,
    width,
    height: PAD_TOP + lanes.length * ROW_H + PAD_BOTTOM,
    plotR,
    todayX: Date.now() >= t0 && Date.now() <= t1 ? x(Date.now()) : null,
    expiryX: cEnd !== null && cEnd <= t1 ? x(cEnd) : null,
    ticks: monthTicks(t0, t1, x),
  };
}

function monthTicks(t0: number, t1: number, x: (t: number) => number) {
  const out: Array<{ x: number; label: string }> = [];
  const d = new Date(t0);
  d.setDate(1);
  d.setHours(0, 0, 0, 0);
  for (let i = 0; i < 48; i++) {
    const t = d.getTime();
    if (t > t1) break;
    if (t >= t0) {
      out.push({ x: x(t), label: d.toLocaleDateString(undefined, { month: "short" }) });
    }
    d.setMonth(d.getMonth() + 1);
  }
  return out;
}

const rowY = (row: number) => PAD_TOP + row * ROW_H + ROW_H / 2;

// ------------------------------------------------------------------ drawing

function Axis({ layout }: { layout: Layout }) {
  const bottom = layout.height - PAD_BOTTOM;

  return (
    <g>
      {layout.ticks.map((t) => (
        <g key={t.x}>
          <line x1={t.x} x2={t.x} y1={PAD_TOP - 10} y2={bottom} stroke="var(--rule)" />
          <text
            x={t.x + 4}
            y={PAD_TOP - 16}
            className="fill-[var(--ink-faint)] text-[9.5px] uppercase tracking-[0.08em]"
          >
            {t.label}
          </text>
        </g>
      ))}

      {layout.expiryX !== null && (
        <line
          x1={layout.expiryX}
          x2={layout.expiryX}
          y1={PAD_TOP - 10}
          y2={bottom}
          stroke="var(--signal)"
          strokeDasharray="2 3"
          opacity={0.6}
        />
      )}

      {layout.todayX !== null && (
        <line
          x1={layout.todayX}
          x2={layout.todayX}
          y1={PAD_TOP - 10}
          y2={bottom}
          stroke="var(--accent)"
          strokeWidth={1.5}
        />
      )}

      {/* Undated work stands past this. Drawing it is the point: work with no
          date is not scheduled, and the diagram should not pretend otherwise. */}
      <line
        x1={layout.plotR + 12}
        x2={layout.plotR + 12}
        y1={PAD_TOP - 10}
        y2={bottom}
        stroke="var(--rule-strong)"
        strokeDasharray="3 3"
      />
    </g>
  );
}

/**
 * One branch: a stub up at each end, the line, and the fill.
 *
 * The fill answers "where are they up to". It runs from the fork in proportion
 * to the goal's progress, so the distance between where it stops and the today
 * rule *is* the schedule variance — read as a gap rather than by comparing two
 * numbers.
 */
function LaneLine({ lane }: { lane: Lane }) {
  const y = rowY(lane.row);
  const py = lane.parentRow === null ? null : rowY(lane.parentRow);
  const root = py === null;
  const colour = stroke(lane.hue);
  const c = root ? 0 : curveFor(y - py!);
  const l = lane.forkX;
  const r = lane.mergeX;
  const progress = lane.goal ? Math.max(0, Math.min(100, lane.goal.progress)) : 0;

  return (
    <g>
      {!root && (
        <>
          <path
            d={`M ${l - c} ${py} C ${l - c * 0.45} ${py} ${l - c * 0.55} ${y} ${l} ${y}`}
            fill="none"
            stroke={colour}
            strokeWidth={1.75}
            opacity={0.4}
          />
          <path
            d={`M ${r} ${y} C ${r + c * 0.55} ${y} ${r + c * 0.45} ${py} ${r + c} ${py}`}
            fill="none"
            stroke={colour}
            strokeWidth={1.75}
            opacity={0.4}
          />
        </>
      )}

      <line x1={l} x2={r} y1={y} y2={y} stroke={colour} strokeWidth={2} opacity={0.32} />

      {progress > 0 && (
        <line
          x1={l}
          x2={l + ((r - l) * progress) / 100}
          y1={y}
          y2={y}
          stroke={colour}
          strokeWidth={3}
          strokeLinecap="round"
        />
      )}
    </g>
  );
}

function DotMark({
  dot,
  lane,
  selected,
  onSelect,
}: {
  dot: Dot;
  lane: Lane;
  selected: boolean;
  onSelect: (goalId: string | null) => void;
}) {
  const y = rowY(lane.row);
  const g = dot.goal;
  const hue = hueFor(g.responsible_party?.id ?? lane.goal?.responsible_party?.id ?? null);
  const colour = stroke(hue);
  const done = g.accepted_at !== null || g.status === "met";

  return (
    <g className="cursor-pointer" onClick={() => onSelect(g.id)}>
      <title>
        {g.title}
        {g.owner?.name ? ` · ${g.owner.name}` : ""}
        {g.due_at ? ` · ${formatDate(g.due_at)}` : " · no date"}
        {` · ${g.progress}%`}
      </title>

      {selected && <circle cx={dot.x} cy={y} r={DOT_R + 5} fill={stroke(hue, 0.18)} />}

      {/* A generous invisible target, so a 6px dot is still easy to hit. */}
      <circle cx={dot.x} cy={y} r={14} fill="transparent" />

      <circle
        cx={dot.x}
        cy={y}
        r={DOT_R}
        fill={done ? colour : "var(--paper)"}
        stroke={g.is_overdue ? "var(--signal)" : colour}
        strokeWidth={g.is_overdue ? 2.5 : 2}
        strokeDasharray={dot.dated ? undefined : "3 2"}
      />

      {/* Part-done work gets a core rather than a second ring, so a dot never
          reads as two states at once. */}
      {!done && g.progress > 0 && (
        <circle cx={dot.x} cy={y} r={DOT_R - 3} fill={colour} opacity={0.75} />
      )}
    </g>
  );
}

/**
 * The gutter: the only text on the drawing.
 *
 * Indented by depth, which is what carries the hierarchy now that the fork stubs
 * no longer reach all the way to the parent's row.
 */
function LaneLabel({
  lane,
  selected,
  onSelect,
}: {
  lane: Lane;
  selected: boolean;
  onSelect: (goalId: string | null) => void;
}) {
  const y = rowY(lane.row);
  const g = lane.goal;
  const indent = lane.depth * 11;

  return (
    <g className={g ? "cursor-pointer" : undefined} onClick={() => g && onSelect(g.id)}>
      <title>{g ? `${g.title} · ${g.progress}%` : "The mission"}</title>

      <rect
        x={0}
        y={y - ROW_H / 2 + 2}
        width={LABEL_W - 16}
        height={ROW_H - 4}
        rx={5}
        fill={selected ? "var(--paper-sunk)" : "transparent"}
      />
      <rect x={indent} y={y - 9} width={2.5} height={18} rx={1.25} fill={stroke(lane.hue)} />

      <text
        x={indent + 9}
        y={y - 1}
        className="fill-[var(--ink)] text-[11.5px] font-[570]"
      >
        {clip(g ? g.title : "The mission", 22 - lane.depth * 2)}
      </text>

      {g && (
        <text x={indent + 9} y={y + 11} className="text-[9.5px]">
          <tspan className="fill-[var(--ink-faint)]">
            {clip(g.responsible_party?.label ?? g.owner?.name ?? "—", 16)}
          </tspan>
          <tspan
            className={
              g.is_overdue ? "fill-[var(--signal)] font-[600]" : "fill-[var(--ink-faint)]"
            }
          >
            {`  ${g.progress}%`}
          </tspan>
        </text>
      )}
    </g>
  );
}

const clip = (s: string, n: number) => (s.length > n ? `${s.slice(0, n - 1)}…` : s);
