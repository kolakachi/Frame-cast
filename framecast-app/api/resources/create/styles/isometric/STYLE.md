# Isometric

A tiny world on a table: every step of a process is a little object on one isometric ground, and one glowing item
travels between them while the camera follows. It makes a process feel simple and complete.

## Look
- True isometric: x and y axes at 30 degrees; a block is three faces (top light, left mid, right dark) of one colour family.
- Soft pastel palette with one bright accent for the travelling item and the labels' dots; a warm off-white ground with a faint tile grid.
- Objects are simple and chunky (a phone, a building, a van, a house, a server), built from blocks; no outlines or thin 2 px ones.
- Labels are small rounded pills in Inter with a dot in the accent colour, floating above their object.

## Motion
- One path through the whole video: the item travels along the ground from stop to stop (straight runs along an axis, a small hop at each stop).
- The camera glides with the item (ease in and out, 1-1.5 s), the world never cuts; earlier stops stay in the world.
- At each stop: the object reacts (a lid opens, a door, a screen lights) and its label pops (WM.pop).
- Objects can build in: blocks drop onto the ground with a small bounce before the item arrives.

## Structure
1. Hook: the start object and what happens there, in frame 1.
2. 2-4 stops, one step each, with a label.
3. Arrival: the item reaches the last stop and something completes (a tick, a light).
4. Close: pull back to show the whole path, then the brand and the call to action.

## Never
- Perspective (vanishing points) mixed with isometric; every edge keeps its axis.
- Detailed, cluttered worlds drawn by hand in code: for a rich scene, use a generated isometric picture and animate a few parts on top.
- Labels that cover the travelling item.
