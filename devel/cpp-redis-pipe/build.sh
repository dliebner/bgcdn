#!/bin/bash
# build.sh -- build script for redis-pipe

set -ex
rm -rf cpp_redis lib; mkdir lib

(  git clone https://github.com/cpp-redis/cpp_redis
   cd cpp_redis
   git checkout fc2c43fa07e45e1ef0f77cbda6a240e7890ae5aa
   git submodule init; git submodule update
   mkdir build; cd build
   cmake .. -D{CMAKE_BUILD_TYPE=Release,CMAKE_INSTALL_PREFIX=../../lib}
   make
   make install
)

g++ -{std=c++17,pthread} -{O3,s} -o redis-pipe{,.cc} -Ilib/include -Llib/lib -l{cpp_redis,tacopie}
